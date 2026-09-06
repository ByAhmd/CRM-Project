<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Enums\ActivityLogEvent;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Filament\Pages\Calendar;
use App\Models\Task;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Services\Tasks\CalendarFeed;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The calendar page (decision D-12): access follows the task permission,
 * the browser configuration follows the locale and the settings, and every
 * write from the calendar goes through TaskService after the policy.
 */
final class CalendarPageTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-09-06 12:00:00'));
    }

    #[Test]
    public function the_page_renders_for_a_rep_with_the_legend_and_the_calendar_hook(): void
    {
        $rep = $this->salesRep();

        $this->actingAs($rep)
            ->get(Calendar::getUrl())
            ->assertOk()
            ->assertSee(__('calendar.title'))
            ->assertSee(__('calendar.legend.tasks'))
            ->assertSee(__('calendar.legend.meetings'))
            ->assertSee(__('calendar.legend.calls'))
            ->assertSee(__('calendar.legend.completed'))
            ->assertSee('x-data="crmCalendar(', false)
            ->assertSee('assets/calendar-', false)
            ->assertSee('class="fi-crm-calendar', false);

        Livewire::actingAs($rep)->test(Calendar::class)->assertOk();
    }

    #[Test]
    public function a_user_without_the_task_permission_cannot_access_the_page(): void
    {
        $nobody = User::factory()->create();

        $this->actingAs($nobody);
        $this->assertFalse(Calendar::canAccess());
        $this->get(Calendar::getUrl())->assertForbidden();
    }

    #[Test]
    public function a_read_only_user_sees_the_page_but_may_neither_create_nor_move(): void
    {
        $readOnly = $this->readOnly();

        $this->actingAs($readOnly);
        $this->assertTrue(Calendar::canAccess());
        $this->get(Calendar::getUrl())->assertOk();

        $config = $this->page($readOnly)->config();

        $this->assertFalse($config['canCreate']);
        $this->assertFalse($config['canMove']);
    }

    #[Test]
    public function a_rep_may_create_and_move_from_the_calendar(): void
    {
        $config = $this->page($this->salesRep())->config();

        $this->assertTrue($config['canCreate']);
        $this->assertTrue($config['canMove']);
    }

    #[Test]
    public function the_config_follows_the_locale_direction_and_settings(): void
    {
        $admin = $this->admin();
        $settings = app(SettingsRepository::class);

        $config = $this->page($admin)->config();

        $this->assertSame('ar', $config['locale']);
        $this->assertSame('rtl', $config['direction']);
        $this->assertSame($settings->weekStartsOn(), $config['firstDay']);
        $this->assertSame($settings->timezone(), $config['timeZone']);
        $this->assertSame('dayGridMonth', $config['initialView']);
        $this->assertTrue($config['nowIndicator']);
        $this->assertSame('06:00:00', $config['slotMinTime']);
        $this->assertSame('22:00:00', $config['slotMaxTime']);
        $this->assertSame(__('calendar.views.month'), $config['views']['month']);
        $this->assertSame(__('calendar.buttons.today'), $config['buttonText']['today']);
        $this->assertSame(__('calendar.texts.all_day'), $config['allDayText']);
        $this->assertSame(__('calendar.texts.no_events'), $config['noEventsText']);
        $this->assertSame(__('calendar.texts.more'), $config['moreLinkText']);
        $this->assertSame(['events' => 'events', 'moveTask' => 'moveTask', 'createTask' => 'createTask', 'editTask' => 'editTask'], $config['methods']);
        $this->assertSame(Calendar::REFRESH_EVENT, $config['refreshEvent']);

        app()->setLocale('en');
        $english = $this->page($admin)->config();

        $this->assertSame('en', $english['locale']);
        $this->assertSame('ltr', $english['direction']);
        $this->assertSame(__('calendar.views.month'), $english['views']['month']);

        $settings->update([SettingsRepository::WEEK_STARTS_ON => 1], $admin);

        $this->assertSame(1, $this->page($admin)->config()['firstDay']);
    }

    #[Test]
    public function events_returns_the_viewers_entries_as_fullcalendar_arrays(): void
    {
        $rep = $this->salesRep();
        $mine = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => '2026-09-10 10:00:00']);
        $theirs = Task::factory()->create(['assignee_id' => $this->salesRep()->getKey(), 'due_at' => '2026-09-10 10:00:00']);

        $events = $this->page($rep)->events('2026-09-01T00:00:00+03:00', '2026-10-01T00:00:00+03:00');

        $this->assertCount(1, $events);
        $this->assertSame('task-'.$mine->getKey(), $events[0]['id']);
        $this->assertSame(
            ['id', 'title', 'start', 'end', 'allDay', 'backgroundColor', 'borderColor', 'textColor', 'url', 'editable', 'classNames', 'extendedProps'],
            array_keys($events[0]),
        );
        $this->assertNotContains('task-'.$theirs->getKey(), array_column($events, 'id'));
    }

    #[Test]
    public function events_refuses_a_range_wider_than_the_cap(): void
    {
        $page = $this->page($this->salesRep());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(__('calendar.validation.range_too_large', ['days' => CalendarFeed::MAX_RANGE_DAYS]));

        $page->events('2026-09-01', '2026-11-03');
    }

    #[Test]
    public function events_accepts_a_range_at_the_cap_and_refuses_an_invalid_or_inverted_one(): void
    {
        $page = $this->page($this->salesRep());

        $this->assertSame([], $page->events('2026-09-01', '2026-11-02'));

        try {
            $page->events('not-a-date', '2026-10-01');
            $this->fail('an invalid date was accepted');
        } catch (ValidationException $exception) {
            $this->assertSame(__('calendar.validation.invalid_date'), $exception->getMessage());
        }

        try {
            $page->events('2026-10-01', '2026-09-01');
            $this->fail('an inverted range was accepted');
        } catch (ValidationException $exception) {
            $this->assertSame(__('calendar.validation.invalid_date'), $exception->getMessage());
        }
    }

    #[Test]
    public function dragging_an_own_task_reschedules_it_through_the_service_and_logs_it(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => '2026-09-10 10:00:00']);

        Livewire::actingAs($rep)
            ->test(Calendar::class)
            ->call('moveTask', $task->getKey(), '2026-09-15T09:30:00+03:00', null, false)
            ->assertReturned(true)
            ->assertNotified(__('calendar.notifications.moved', ['task' => $task->title, 'date' => '2026-09-15 09:30']))
            ->assertDispatched(Calendar::REFRESH_EVENT);

        $task->refresh();
        $this->assertSame('2026-09-15 09:30:00', $task->due_at?->format('Y-m-d H:i:s'));
        $this->assertNull($task->starts_at);
        $this->assertNull($task->ends_at);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskUpdated->value, 'subject_id' => $task->getKey(), 'causer_id' => $rep->getKey()]);
    }

    #[Test]
    public function an_all_day_drop_keeps_the_tasks_time_of_day(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => '2026-09-10 16:45:00']);

        Livewire::actingAs($rep)
            ->test(Calendar::class)
            ->call('moveTask', $task->getKey(), '2026-09-18', null, true)
            ->assertReturned(true);

        $this->assertSame('2026-09-18 16:45:00', $task->refresh()->due_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_meeting_keeps_its_duration_when_dragged_and_takes_the_new_end_when_resized(): void
    {
        $rep = $this->salesRep();
        $meeting = Task::factory()->create([
            'assignee_id' => $rep->getKey(),
            'kind' => TaskKind::Meeting,
            'due_at' => '2026-09-10 10:00:00',
            'starts_at' => '2026-09-10 10:00:00',
            'ends_at' => '2026-09-10 11:00:00',
        ]);

        Livewire::actingAs($rep)
            ->test(Calendar::class)
            ->call('moveTask', $meeting->getKey(), '2026-09-20T14:00:00+03:00', null, false)
            ->assertReturned(true);

        $meeting->refresh();
        $this->assertSame('2026-09-20 14:00:00', $meeting->due_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-20 14:00:00', $meeting->starts_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-20 15:00:00', $meeting->ends_at?->format('Y-m-d H:i:s'));

        Livewire::actingAs($rep)
            ->test(Calendar::class)
            ->call('moveTask', $meeting->getKey(), '2026-09-20T14:00:00+03:00', '2026-09-20T16:30:00+03:00', false)
            ->assertReturned(true);

        $meeting->refresh();
        $this->assertSame('2026-09-20 14:00:00', $meeting->starts_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-20 16:30:00', $meeting->ends_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_closed_task_and_an_end_before_the_start_are_refused_with_a_notification(): void
    {
        $rep = $this->salesRep();
        $done = Task::factory()->completed()->create(['assignee_id' => $rep->getKey(), 'due_at' => '2026-09-10 10:00:00']);
        $meeting = Task::factory()->create([
            'assignee_id' => $rep->getKey(),
            'kind' => TaskKind::Meeting,
            'due_at' => '2026-09-10 10:00:00',
            'starts_at' => '2026-09-10 10:00:00',
            'ends_at' => '2026-09-10 11:00:00',
        ]);

        Livewire::actingAs($rep)
            ->test(Calendar::class)
            ->call('moveTask', $done->getKey(), '2026-09-15T09:30:00+03:00', null, false)
            ->assertReturned(false)
            ->assertNotified(Notification::make()->title(__('calendar.notifications.refused'))->body(__('tasks.validation.not_open'))->danger())
            ->assertNotDispatched(Calendar::REFRESH_EVENT)
            ->call('moveTask', $meeting->getKey(), '2026-09-20T14:00:00+03:00', '2026-09-20T13:00:00+03:00', false)
            ->assertReturned(false)
            ->assertNotified(Notification::make()->title(__('calendar.notifications.refused'))->body(__('tasks.validation.ends_before_starts'))->danger())
            ->call('moveTask', $meeting->getKey(), 'not-a-date', null, false)
            ->assertReturned(false)
            ->assertNotified(Notification::make()->title(__('calendar.notifications.refused'))->body(__('calendar.validation.invalid_date'))->danger());

        $this->assertSame('2026-09-10 10:00:00', $done->refresh()->due_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-10 10:00:00', $meeting->refresh()->starts_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_read_only_user_cannot_move_a_task_they_can_see(): void
    {
        $readOnly = $this->readOnly();
        $task = Task::factory()->create(['assignee_id' => $this->salesRep()->getKey(), 'due_at' => '2026-09-10 10:00:00']);

        $this->assertContains('task-'.$task->getKey(), array_column($this->page($readOnly)->events('2026-09-01', '2026-10-01'), 'id'));

        Livewire::actingAs($readOnly)
            ->test(Calendar::class)
            ->call('moveTask', $task->getKey(), '2026-09-15T09:30:00+03:00', null, false)
            ->assertForbidden();

        $this->assertSame('2026-09-10 10:00:00', $task->refresh()->due_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function another_reps_task_cannot_be_moved(): void
    {
        $rep = $this->salesRep();
        $theirs = Task::factory()->create(['assignee_id' => $this->salesRep()->getKey(), 'due_at' => '2026-09-10 10:00:00']);

        Livewire::actingAs($rep)
            ->test(Calendar::class)
            ->call('moveTask', $theirs->getKey(), '2026-09-15T09:30:00+03:00', null, false)
            ->assertNotFound();

        $this->assertSame('2026-09-10 10:00:00', $theirs->refresh()->due_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function clicking_a_day_creates_a_task_due_on_that_day_and_refreshes_the_calendar(): void
    {
        $rep = $this->salesRep();

        Livewire::actingAs($rep)
            ->test(Calendar::class)
            ->mountAction('createTask', ['start' => '2026-09-18'])
            ->assertActionMounted('createTask')
            ->fillForm(['title' => 'From the calendar'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified(__('calendar.notifications.created'))
            ->assertDispatched(Calendar::REFRESH_EVENT);

        $task = Task::query()->where('title', 'From the calendar')->firstOrFail();

        $this->assertSame('2026-09-18 '.sprintf('%02d:00:00', CalendarFeed::DEFAULT_DUE_HOUR), $task->due_at?->format('Y-m-d H:i:s'));
        $this->assertSame(TaskKind::Task, $task->kind);
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertSame($rep->getKey(), (int) $task->assignee_id);
        $this->assertSame($rep->getKey(), (int) $task->created_by);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskCreated->value, 'subject_id' => $task->getKey(), 'causer_id' => $rep->getKey()]);
    }

    #[Test]
    public function clicking_a_time_slot_creates_a_task_due_at_that_moment(): void
    {
        $rep = $this->salesRep();

        Livewire::actingAs($rep)
            ->test(Calendar::class)
            ->mountAction('createTask', ['start' => '2026-09-18T14:30:00+03:00'])
            ->fillForm(['title' => 'Slot task'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('2026-09-18 14:30:00', Task::query()->where('title', 'Slot task')->firstOrFail()->due_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_read_only_user_cannot_create_from_the_calendar(): void
    {
        Livewire::actingAs($this->readOnly())
            ->test(Calendar::class)
            ->assertActionHidden('createTask')
            ->mountAction('createTask', ['start' => '2026-09-18'])
            ->assertActionNotMounted('createTask');

        $this->assertSame(0, Task::query()->count());
    }

    #[Test]
    public function the_edit_modal_updates_the_task_through_the_service(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'title' => 'Before', 'due_at' => '2026-09-10 10:00:00']);

        Livewire::actingAs($rep)
            ->test(Calendar::class)
            ->mountAction('editTask', ['task' => $task->getKey()])
            ->assertActionMounted('editTask')
            ->assertActionDataSet(['title' => 'Before', 'owner_id' => $rep->getKey()])
            ->fillForm(['title' => 'After'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified(__('calendar.notifications.updated'))
            ->assertDispatched(Calendar::REFRESH_EVENT);

        $task->refresh();
        $this->assertSame('After', $task->title);
        $this->assertSame('2026-09-10 10:00:00', $task->due_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskUpdated->value, 'subject_id' => $task->getKey(), 'causer_id' => $rep->getKey()]);
    }

    #[Test]
    public function the_edit_modal_completes_the_task_through_its_footer_action(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => '2026-09-10 10:00:00']);

        Livewire::actingAs($rep)
            ->test(Calendar::class)
            ->callAction([TestAction::make('editTask')->arguments(['task' => $task->getKey()]), 'complete'], ['completion_note' => 'Done from the calendar'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('tasks.notifications.completed'))
            ->assertDispatched(Calendar::REFRESH_EVENT);

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertNotNull($task->completed_at);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskCompleted->value, 'subject_id' => $task->getKey(), 'causer_id' => $rep->getKey()]);
    }

    #[Test]
    public function the_complete_footer_action_is_absent_on_a_closed_task(): void
    {
        $rep = $this->salesRep();
        $done = Task::factory()->completed()->create(['assignee_id' => $rep->getKey(), 'due_at' => '2026-09-10 10:00:00']);

        Livewire::actingAs($rep)
            ->test(Calendar::class)
            ->assertActionVisible(TestAction::make('editTask')->arguments(['task' => $done->getKey()]))
            ->assertActionHidden([TestAction::make('editTask')->arguments(['task' => $done->getKey()]), 'complete']);
    }

    #[Test]
    public function the_edit_modal_does_not_open_for_a_task_outside_the_actors_reach(): void
    {
        $rep = $this->salesRep();
        $readOnly = $this->readOnly();
        $theirs = Task::factory()->create(['assignee_id' => $this->salesRep()->getKey(), 'title' => 'Theirs', 'due_at' => '2026-09-10 10:00:00']);

        Livewire::actingAs($rep)
            ->test(Calendar::class)
            ->assertActionHidden(TestAction::make('editTask')->arguments(['task' => $theirs->getKey()]))
            ->mountAction('editTask', ['task' => $theirs->getKey()])
            ->assertActionNotMounted('editTask');

        Livewire::actingAs($readOnly)
            ->test(Calendar::class)
            ->assertActionHidden(TestAction::make('editTask')->arguments(['task' => $theirs->getKey()]))
            ->mountAction('editTask', ['task' => $theirs->getKey()])
            ->assertActionNotMounted('editTask');

        $this->assertSame('Theirs', $theirs->refresh()->title);
    }

    /** The page as the given user, for calling its public methods directly. */
    private function page(User $user): Calendar
    {
        $page = Livewire::actingAs($user)->test(Calendar::class)->instance();
        assert($page instanceof Calendar);

        return $page;
    }
}
