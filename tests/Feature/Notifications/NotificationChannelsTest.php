<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ActivityLogEvent;
use App\Enums\NotificationEvent;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\NotificationPreference;
use App\Models\Task;
use App\Notifications\RecordAssignedNotification;
use App\Notifications\TaskOverdueNotification;
use App\Notifications\TaskReminderNotification;
use App\Services\Notifications\NotificationPreferenceService;
use App\Support\Notifications\NotificationChannels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Channel resolution (plan section 3.6, decision D-10): the bell unless the
 * user switched it off, mail only with a real transport AND an opt-in, one
 * choice per event, and every notification asks the same question.
 */
final class NotificationChannelsTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        config()->set('mail.default', 'log');
    }

    #[Test]
    public function without_a_row_every_event_travels_on_the_bell_only(): void
    {
        $rep = $this->salesRep();

        foreach (NotificationEvent::cases() as $event) {
            $this->assertSame(['database'], NotificationChannels::for($rep, $event), $event->value);
        }

        $matrix = $this->service()->matrixFor($rep);

        $this->assertCount(count(NotificationEvent::cases()), $matrix);

        foreach (NotificationEvent::cases() as $event) {
            $this->assertSame(['database' => true, 'mail' => false], $matrix[$event->value]);
        }
    }

    #[Test]
    public function mail_is_added_only_with_a_real_transport_and_the_users_opt_in(): void
    {
        $rep = $this->salesRep();

        // A transport alone: still bell only.
        config()->set('mail.default', 'smtp');
        $this->assertSame(['database'], NotificationChannels::for($rep, NotificationEvent::DealClosed));

        // An opt-in alone (log mailer): still bell only.
        NotificationPreference::factory()->ofEvent(NotificationEvent::DealClosed)->withMail()->create(['user_id' => $rep->getKey()]);
        config()->set('mail.default', 'log');
        $this->assertSame(['database'], NotificationChannels::for($rep, NotificationEvent::DealClosed));

        config()->set('mail.default', 'array');
        $this->assertSame(['database'], NotificationChannels::for($rep, NotificationEvent::DealClosed));

        // Both: bell and mail.
        config()->set('mail.default', 'smtp');
        $this->assertSame(['database', 'mail'], NotificationChannels::for($rep, NotificationEvent::DealClosed));
    }

    #[Test]
    public function a_user_may_switch_the_bell_off_per_event(): void
    {
        $rep = $this->salesRep();
        config()->set('mail.default', 'smtp');

        NotificationPreference::factory()->ofEvent(NotificationEvent::LeadStale)->withoutDatabase()->create(['user_id' => $rep->getKey()]);

        $this->assertSame([], NotificationChannels::for($rep, NotificationEvent::LeadStale));

        NotificationPreference::factory()->ofEvent(NotificationEvent::NoteMention)->withoutDatabase()->withMail()->create(['user_id' => $rep->getKey()]);

        $this->assertSame(['mail'], NotificationChannels::for($rep, NotificationEvent::NoteMention));
    }

    #[Test]
    public function preferences_are_independent_per_event_and_per_user(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        config()->set('mail.default', 'smtp');

        NotificationPreference::factory()->ofEvent(NotificationEvent::TaskReminder)->withMail()->create(['user_id' => $rep->getKey()]);

        $this->assertSame(['database', 'mail'], NotificationChannels::for($rep, NotificationEvent::TaskReminder));
        $this->assertSame(['database'], NotificationChannels::for($rep, NotificationEvent::TaskOverdue));
        $this->assertSame(['database'], NotificationChannels::for($other, NotificationEvent::TaskReminder));

        $matrix = $this->service()->matrixFor($rep);

        $this->assertTrue($matrix[NotificationEvent::TaskReminder->value]['mail']);
        $this->assertFalse($matrix[NotificationEvent::TaskOverdue->value]['mail']);
    }

    #[Test]
    public function the_existing_notifications_route_through_the_event_preferences(): void
    {
        $rep = $this->salesRep();
        $manager = $this->salesManager();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $task = Task::factory()->create(['assignee_id' => $rep->getKey()]);

        config()->set('mail.default', 'smtp');

        NotificationPreference::factory()->ofEvent(NotificationEvent::RecordAssigned)->withoutDatabase()->create(['user_id' => $rep->getKey()]);
        NotificationPreference::factory()->ofEvent(NotificationEvent::TaskReminder)->withMail()->create(['user_id' => $rep->getKey()]);
        NotificationPreference::factory()->ofEvent(NotificationEvent::TaskOverdue)->withoutDatabase()->withMail()->create(['user_id' => $rep->getKey()]);

        $this->assertSame([], (new RecordAssignedNotification($account, $manager))->via($rep));
        $this->assertSame(['database', 'mail'], (new TaskReminderNotification($task))->via($rep));
        $this->assertSame(['mail'], (new TaskOverdueNotification($task))->via($rep));
    }

    #[Test]
    public function updating_writes_only_the_rows_that_changed_and_audits_them_on_the_user(): void
    {
        $rep = $this->salesRep();
        $admin = $this->admin();

        $this->service()->update($rep, [
            NotificationEvent::RecordAssigned->value => ['database' => true, 'mail' => false],
            NotificationEvent::DealClosed->value => ['database' => false, 'mail' => true],
        ], $admin);

        // Only the entry that differs from the defaults is stored.
        $this->assertSame(1, NotificationPreference::query()->where('user_id', $rep->getKey())->count());
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $rep->getKey(),
            'event' => NotificationEvent::DealClosed->value,
            'database' => 0,
            'mail' => 1,
        ]);

        $log = ActivityLog::query()->where('description', ActivityLogEvent::UserNotificationPreferencesUpdated->value)->latest('id')->firstOrFail();

        $this->assertSame($rep->getKey(), (int) $log->subject_id);
        $this->assertSame($admin->getKey(), (int) $log->causer_id);
        $this->assertSame($rep->name, $log->properties->get('subject_label'));
        $changes = $log->properties->get('changes');

        $this->assertIsArray($changes);
        $this->assertSame([NotificationEvent::DealClosed->value], array_keys($changes));
        $this->assertFalse($changes[NotificationEvent::DealClosed->value]['database']);
        $this->assertTrue($changes[NotificationEvent::DealClosed->value]['mail']);

        // Saving the same matrix again is a no-op: no new row, no new audit entry.
        $this->service()->update($rep, [NotificationEvent::DealClosed->value => ['database' => false, 'mail' => true]], $rep);

        $this->assertSame(1, ActivityLog::query()->where('description', ActivityLogEvent::UserNotificationPreferencesUpdated->value)->count());

        // Flipping it back updates the same row.
        $this->service()->update($rep, [NotificationEvent::DealClosed->value => ['database' => true, 'mail' => true]], $rep);

        $this->assertSame(1, NotificationPreference::query()->where('user_id', $rep->getKey())->count());
        $this->assertSame(['database' => true, 'mail' => true], $this->service()->matrixFor($rep)[NotificationEvent::DealClosed->value]);
        $this->assertSame(2, ActivityLog::query()->where('description', ActivityLogEvent::UserNotificationPreferencesUpdated->value)->count());
    }

    #[Test]
    public function an_unknown_event_is_refused(): void
    {
        $rep = $this->salesRep();

        $this->expectException(InvalidArgumentException::class);

        $this->service()->update($rep, ['payment_received' => ['database' => true, 'mail' => false]], $rep);
    }

    #[Test]
    public function a_channel_that_is_not_a_boolean_is_refused_and_nothing_is_written(): void
    {
        $rep = $this->salesRep();

        try {
            $this->service()->update($rep, [
                NotificationEvent::DealClosed->value => ['database' => false, 'mail' => true],
                NotificationEvent::LeadStale->value => ['database' => 'yes', 'mail' => false],
            ], $rep);
            $this->fail('A non-boolean channel was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, NotificationPreference::query()->count());
        }
    }

    private function service(): NotificationPreferenceService
    {
        return app(NotificationPreferenceService::class);
    }
}
