<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\NotificationEvent;
use App\Enums\Permission;
use App\Filament\Pages\NotificationPreferences;
use App\Models\ActivityLog;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Notifications\NotificationPreferenceService;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The preferences page offers only what a user can receive (D-19): each event
 * is offered to holders of the permission its notices depend on, a section
 * with no offered event disappears, and saving never writes — nor changes —
 * a row for an event the user was not offered, even from a forged request.
 */
final class NotificationPreferencesVisibilityTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const TASK_EVENTS = ['task_reminder', 'task_overdue', 'task_completed', 'task_progress', 'task_comment'];

    private const SALES_EVENTS = ['deal_stage_changed', 'deal_closed', 'lead_converted', 'lead_stale'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
        config()->set('mail.default', 'smtp');
    }

    /**
     * @return array<string, array{CrmRole, list<string>, list<string>}>
     */
    public static function roles(): array
    {
        $all = ['records', 'tasks', 'deals', 'leads', 'notes'];

        return [
            'super admin: everything, the failed backup included' => [
                CrmRole::SuperAdmin,
                ['record_assigned', ...self::TASK_EVENTS, ...self::SALES_EVENTS, 'note_mention', 'weekly_summary', 'backup_failed'],
                [...$all, 'summaries', 'system'],
            ],
            'admin: everything but the failed backup' => [
                CrmRole::Admin,
                ['record_assigned', ...self::TASK_EVENTS, ...self::SALES_EVENTS, 'note_mention', 'weekly_summary'],
                [...$all, 'summaries'],
            ],
            'sales manager: no summary (no task.assign) and no backup' => [
                CrmRole::SalesManager,
                ['record_assigned', ...self::TASK_EVENTS, ...self::SALES_EVENTS, 'note_mention'],
                $all,
            ],
            'sales rep: records, tasks, deals, leads, notes' => [
                CrmRole::SalesRep,
                ['record_assigned', ...self::TASK_EVENTS, ...self::SALES_EVENTS, 'note_mention'],
                $all,
            ],
            'support: records, tasks, deals, leads, notes' => [
                CrmRole::Support,
                ['record_assigned', ...self::TASK_EVENTS, ...self::SALES_EVENTS, 'note_mention'],
                $all,
            ],
            'read only: no note mentions (no note.create)' => [
                CrmRole::ReadOnly,
                ['record_assigned', ...self::TASK_EVENTS, ...self::SALES_EVENTS],
                ['records', 'tasks', 'deals', 'leads'],
            ],
            'employee: their tasks and assignments only' => [
                CrmRole::Employee,
                ['record_assigned', ...self::TASK_EVENTS],
                ['records', 'tasks'],
            ],
        ];
    }

    /**
     * @param  list<string>  $events
     * @param  list<string>  $sections
     */
    #[Test]
    #[DataProvider('roles')]
    public function each_seeded_role_is_offered_exactly_the_events_its_permissions_can_receive(CrmRole $role, array $events, array $sections): void
    {
        $user = $this->makeUser($role);

        $offered = array_map(
            static fn (NotificationEvent $event): string => $event->value,
            app(NotificationPreferenceService::class)->offeredEvents($user),
        );

        $this->assertEqualsCanonicalizing($events, $offered);

        $page = Livewire::actingAs($user)->test(NotificationPreferences::class);
        $page->assertOk();

        foreach (NotificationEvent::cases() as $event) {
            if (in_array($event->value, $events, true)) {
                $page
                    ->assertFormFieldExists($event->value.'.database')
                    ->assertFormFieldExists($event->value.'.mail');
            } else {
                $page
                    ->assertFormFieldDoesNotExist($event->value.'.database')
                    ->assertFormFieldDoesNotExist($event->value.'.mail');
            }
        }

        $this->assertSame(
            array_map(static fn (string $section): string => __('notifications.sections.'.$section), $sections),
            $this->sectionHeadings($page),
        );
    }

    #[Test]
    public function every_event_is_placed_in_exactly_one_section(): void
    {
        $placed = array_merge(...array_values(NotificationPreferences::groups()));

        $this->assertCount(count(NotificationEvent::cases()), $placed);
        $this->assertEqualsCanonicalizing(NotificationEvent::cases(), $placed);
        $this->assertContains(NotificationEvent::TaskComment, NotificationPreferences::groups()['tasks']);
        $this->assertSame([NotificationEvent::WeeklySummary], NotificationPreferences::groups()['summaries']);
        $this->assertSame([NotificationEvent::BackupFailed], NotificationPreferences::groups()['system']);
    }

    #[Test]
    public function any_one_of_an_events_permissions_offers_it(): void
    {
        $service = app(NotificationPreferenceService::class);

        // Record assignment follows any owned entity the user may open.
        $contactsOnly = $this->userWithPermissions(null, [Permission::ContactViewAny]);

        $this->assertSame([NotificationEvent::RecordAssigned], $service->offeredEvents($contactsOnly));

        $mentionsOnly = $this->userWithPermissions(null, [Permission::NoteCreate]);

        $this->assertSame([NotificationEvent::NoteMention], $service->offeredEvents($mentionsOnly));
        $this->assertSame([__('notifications.sections.notes')], $this->sectionHeadings(
            Livewire::actingAs($mentionsOnly)->test(NotificationPreferences::class),
        ));
    }

    #[Test]
    public function a_user_offered_nothing_sees_an_empty_state_and_no_save_button(): void
    {
        $nobody = $this->userWithPermissions(null, []);

        $this->assertSame([], app(NotificationPreferenceService::class)->offeredEvents($nobody));

        $page = Livewire::actingAs($nobody)->test(NotificationPreferences::class);
        $page->assertOk();

        $this->assertSame([], $this->sectionHeadings($page));
        $this->assertNotEmpty(array_filter(
            $this->page($page)->form->getComponents(),
            static fn (object $component): bool => $component instanceof EmptyState,
        ));

        $page
            ->assertSee(__('notifications.empty.heading'))
            ->assertDontSee(__('notifications.helpers.mail_not_configured_title'))
            ->assertDontSee(__('notifications.actions.save'));
    }

    #[Test]
    public function a_forged_submission_for_events_not_offered_writes_nothing_and_keeps_stored_choices(): void
    {
        $rep = $this->salesRep();

        // A choice stored while the user was offered the summary (e.g. under an earlier role).
        NotificationPreference::factory()->for($rep)->ofEvent(NotificationEvent::WeeklySummary)->withoutDatabase()->withMail()->create();

        Livewire::actingAs($rep)
            ->test(NotificationPreferences::class)
            ->set('data.'.NotificationEvent::WeeklySummary->value, ['database' => true, 'mail' => false])
            ->set('data.'.NotificationEvent::BackupFailed->value, ['database' => false, 'mail' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('notification_preferences', ['user_id' => $rep->getKey(), 'event' => NotificationEvent::WeeklySummary->value, 'database' => 0, 'mail' => 1]);
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $rep->getKey(), 'event' => NotificationEvent::BackupFailed->value]);
        $this->assertSame(1, NotificationPreference::query()->where('user_id', $rep->getKey())->count());
        $this->assertFalse(ActivityLog::query()->where('description', ActivityLogEvent::UserNotificationPreferencesUpdated->value)->exists());
    }

    #[Test]
    public function the_service_drops_keys_for_events_not_offered_and_writes_only_the_offered_ones(): void
    {
        $employee = $this->employee();

        app(NotificationPreferenceService::class)->update($employee, [
            NotificationEvent::DealClosed->value => ['database' => false, 'mail' => true],
            NotificationEvent::BackupFailed->value => ['database' => false, 'mail' => false],
            NotificationEvent::TaskComment->value => ['database' => true, 'mail' => true],
        ], $employee);

        $this->assertSame(
            [NotificationEvent::TaskComment->value],
            NotificationPreference::query()->where('user_id', $employee->getKey())->pluck('event')->map(static fn (NotificationEvent $event): string => $event->value)->all(),
        );

        $log = ActivityLog::query()->where('description', ActivityLogEvent::UserNotificationPreferencesUpdated->value)->sole();
        $changes = $log->properties->get('changes');

        $this->assertIsArray($changes);
        $this->assertSame([NotificationEvent::TaskComment->value], array_keys($changes));
    }

    #[Test]
    public function a_hidden_choice_comes_back_unchanged_when_the_permission_returns(): void
    {
        $user = $this->userWithPermissions(null, [Permission::TaskViewAny, Permission::LeadViewAny]);

        NotificationPreference::factory()->for($user)->ofEvent(NotificationEvent::LeadStale)->withoutDatabase()->withMail()->create();

        // The lead permission is taken away: the event disappears, saving leaves its row alone.
        $user->revokePermissionTo(Permission::LeadViewAny->value);
        $user = $this->refreshed($user);

        Livewire::actingAs($user)
            ->test(NotificationPreferences::class)
            ->assertFormFieldDoesNotExist(NotificationEvent::LeadStale->value.'.database')
            ->fillForm([NotificationEvent::TaskOverdue->value.'.database' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('notification_preferences', ['user_id' => $user->getKey(), 'event' => NotificationEvent::LeadStale->value, 'database' => 0, 'mail' => 1]);
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $user->getKey(), 'event' => NotificationEvent::TaskOverdue->value, 'database' => 0]);

        // It is granted again: the page shows the choice the user made before.
        $user->givePermissionTo(Permission::LeadViewAny->value);
        $user = $this->refreshed($user);

        Livewire::actingAs($user)
            ->test(NotificationPreferences::class)
            ->assertSchemaStateSet([
                NotificationEvent::LeadStale->value.'.database' => false,
                NotificationEvent::LeadStale->value.'.mail' => true,
            ]);
    }

    /**
     * The headings of the sections the page renders, in display order.
     *
     * @return list<string>
     */
    private function sectionHeadings(Testable $page): array
    {
        $headings = [];

        foreach ($this->page($page)->form->getComponents() as $component) {
            if ($component instanceof Section) {
                $headings[] = (string) $component->getHeading();
            }
        }

        return $headings;
    }

    private function page(Testable $page): NotificationPreferences
    {
        $instance = $page->instance();
        $this->assertInstanceOf(NotificationPreferences::class, $instance);

        return $instance;
    }

    private function refreshed(User $user): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh() ?? $user;
    }
}
