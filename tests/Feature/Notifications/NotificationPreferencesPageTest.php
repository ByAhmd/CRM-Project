<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ActivityLogEvent;
use App\Enums\NavigationGroup;
use App\Enums\NotificationEvent;
use App\Filament\Pages\NotificationPreferences;
use App\Models\ActivityLog;
use App\Models\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The preferences page (plan section 3.6): every signed-in user reaches it,
 * sees one bell/mail pair per event, saves through the service (rows
 * upserted, change audited) and cannot switch mail on while the
 * installation has no mailer.
 */
final class NotificationPreferencesPageTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
        config()->set('mail.default', 'log');
    }

    #[Test]
    public function every_signed_in_user_reaches_the_page_and_a_guest_does_not(): void
    {
        $this->assertSame(NavigationGroup::System, NotificationPreferences::getNavigationGroup());

        $this->actingAs($this->salesRep())->get(NotificationPreferences::getUrl())->assertOk();
        $this->actingAs($this->readOnly())->get(NotificationPreferences::getUrl())->assertOk();
        $this->actingAs($this->support())->get(NotificationPreferences::getUrl())->assertOk();

        auth()->logout();

        $this->get(NotificationPreferences::getUrl())->assertRedirect();
    }

    #[Test]
    public function the_form_is_filled_with_the_defaults_and_lists_every_event_once(): void
    {
        $rep = $this->salesRep();

        $page = Livewire::actingAs($rep)->test(NotificationPreferences::class);
        $page->assertOk();

        $listed = [];

        foreach (NotificationPreferences::groups() as $events) {
            foreach ($events as $event) {
                $listed[] = $event;

                $page
                    ->assertFormFieldExists($event->value.'.database')
                    ->assertFormFieldExists($event->value.'.mail')
                    ->assertSchemaStateSet([
                        $event->value.'.database' => true,
                        $event->value.'.mail' => false,
                    ]);
            }
        }

        $this->assertEqualsCanonicalizing(NotificationEvent::cases(), $listed);
        $this->assertCount(count(NotificationEvent::cases()), $listed);
    }

    #[Test]
    public function saving_upserts_the_rows_and_audits_the_changed_entries_on_the_user(): void
    {
        config()->set('mail.default', 'smtp');

        $rep = $this->salesRep();

        Livewire::actingAs($rep)
            ->test(NotificationPreferences::class)
            ->fillForm([
                NotificationEvent::DealClosed->value.'.mail' => true,
                NotificationEvent::LeadStale->value.'.database' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('notifications.notifications.saved'));

        $this->assertSame(2, NotificationPreference::query()->where('user_id', $rep->getKey())->count());
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $rep->getKey(), 'event' => NotificationEvent::DealClosed->value, 'database' => 1, 'mail' => 1]);
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $rep->getKey(), 'event' => NotificationEvent::LeadStale->value, 'database' => 0, 'mail' => 0]);

        $log = ActivityLog::query()->where('description', ActivityLogEvent::UserNotificationPreferencesUpdated->value)->latest('id')->firstOrFail();

        $this->assertSame($rep->getKey(), (int) $log->subject_id);
        $this->assertSame($rep->getKey(), (int) $log->causer_id);
        $this->assertSame($rep->name, $log->properties->get('subject_label'));
        $changes = $log->properties->get('changes');

        $this->assertIsArray($changes);
        $this->assertEqualsCanonicalizing([NotificationEvent::DealClosed->value, NotificationEvent::LeadStale->value], array_keys($changes));
        $this->assertTrue($changes[NotificationEvent::DealClosed->value]['database']);
        $this->assertTrue($changes[NotificationEvent::DealClosed->value]['mail']);
        $this->assertFalse($changes[NotificationEvent::LeadStale->value]['database']);
        $this->assertFalse($changes[NotificationEvent::LeadStale->value]['mail']);

        // A second visit shows what was saved, and saving it unchanged writes nothing new.
        Livewire::actingAs($rep)
            ->test(NotificationPreferences::class)
            ->assertSchemaStateSet([
                NotificationEvent::DealClosed->value.'.mail' => true,
                NotificationEvent::LeadStale->value.'.database' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, NotificationPreference::query()->where('user_id', $rep->getKey())->count());
        $this->assertSame(1, ActivityLog::query()->where('description', ActivityLogEvent::UserNotificationPreferencesUpdated->value)->count());
    }

    #[Test]
    public function the_mail_toggles_are_disabled_while_no_mailer_is_configured(): void
    {
        $rep = $this->salesRep();

        $page = Livewire::actingAs($rep)->test(NotificationPreferences::class);

        foreach (NotificationEvent::cases() as $event) {
            $page
                ->assertFormFieldDisabled($event->value.'.mail')
                ->assertFormFieldEnabled($event->value.'.database');
        }

        $page->assertSee(__('notifications.helpers.mail_not_configured'));

        config()->set('mail.default', 'smtp');

        $page = Livewire::actingAs($rep)->test(NotificationPreferences::class);

        foreach (NotificationEvent::cases() as $event) {
            $page->assertFormFieldEnabled($event->value.'.mail');
        }

        $page->assertDontSee(__('notifications.helpers.mail_not_configured'));
    }

    #[Test]
    public function a_user_only_ever_saves_their_own_preferences(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();

        Livewire::actingAs($rep)
            ->test(NotificationPreferences::class)
            ->fillForm([NotificationEvent::NoteMention->value.'.database' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('notification_preferences', ['user_id' => $rep->getKey(), 'event' => NotificationEvent::NoteMention->value, 'database' => 0]);
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $other->getKey()]);
    }
}
