<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Enums\ActivityKind;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Services\Tasks\CalendarEvent;
use App\Services\Tasks\CalendarFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The calendar read model (decision D-12): what lands on the calendar, in
 * which shape and colour, and only inside the viewer's scope (D-4).
 */
final class CalendarFeedTest extends TestCase
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
    public function a_rep_gets_their_own_tasks_and_not_another_reps(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $mine = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => '2026-09-10 10:00:00']);
        $theirs = Task::factory()->create(['assignee_id' => $other->getKey(), 'due_at' => '2026-09-10 10:00:00']);

        $ids = $this->ids($rep);

        $this->assertContains('task-'.$mine->getKey(), $ids);
        $this->assertNotContains('task-'.$theirs->getKey(), $ids);
    }

    #[Test]
    public function a_manager_gets_the_teams_tasks_and_not_an_outsiders(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $outsider = $this->salesRep();
        $repTask = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => '2026-09-10 10:00:00']);
        $outsiderTask = Task::factory()->create(['assignee_id' => $outsider->getKey(), 'due_at' => '2026-09-10 10:00:00']);

        $ids = $this->ids($manager);

        $this->assertContains('task-'.$repTask->getKey(), $ids);
        $this->assertNotContains('task-'.$outsiderTask->getKey(), $ids);
    }

    #[Test]
    public function a_task_with_a_start_and_an_end_is_a_timed_event_with_an_end(): void
    {
        $rep = $this->salesRep();
        $meeting = Task::factory()->create([
            'assignee_id' => $rep->getKey(),
            'kind' => TaskKind::Meeting,
            'due_at' => '2026-09-10 10:00:00',
            'starts_at' => '2026-09-10 10:00:00',
            'ends_at' => '2026-09-10 11:30:00',
        ]);

        $event = $this->event($rep, 'task-'.$meeting->getKey());

        $this->assertFalse($event->allDay);
        $this->assertSame('2026-09-10T10:00:00+03:00', $event->start);
        $this->assertSame('2026-09-10T11:30:00+03:00', $event->end);
        $this->assertSame($meeting->title, $event->title);
        $this->assertSame(TaskKind::Meeting->value, $event->extendedProps['kind']);
        $this->assertSame(TaskResource::getUrl('view', ['record' => $meeting]), $event->url);
    }

    #[Test]
    public function a_due_only_task_is_an_all_day_event_on_its_due_date(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $task = Task::factory()->create([
            'assignee_id' => $rep->getKey(),
            'lead_id' => $lead->getKey(),
            'priority' => TaskPriority::High,
            'due_at' => '2026-09-10 16:45:00',
        ]);

        $event = $this->event($rep, 'task-'.$task->getKey());

        $this->assertTrue($event->allDay);
        $this->assertSame('2026-09-10', $event->start);
        $this->assertNull($event->end);
        $this->assertSame('var(--crm-color-warning)', $event->backgroundColor);
        $this->assertSame('var(--crm-color-warning)', $event->borderColor);
        $this->assertTrue($event->editable);
        $this->assertSame([], $event->classNames);
        $this->assertSame('task', $event->extendedProps['type']);
        $this->assertSame(TaskStatus::Pending->value, $event->extendedProps['status']);
        $this->assertSame(TaskPriority::High->value, $event->extendedProps['priority']);
        $this->assertSame($task->subjectLabel(), $event->extendedProps['subject']);
    }

    #[Test]
    public function a_completed_task_carries_the_status_colour_and_is_muted_and_not_draggable(): void
    {
        $rep = $this->salesRep();
        $done = Task::factory()->completed()->create(['assignee_id' => $rep->getKey(), 'priority' => TaskPriority::Urgent, 'due_at' => '2026-09-10 10:00:00']);
        $cancelled = Task::factory()->cancelled()->create(['assignee_id' => $rep->getKey(), 'due_at' => '2026-09-11 10:00:00']);

        $completedEvent = $this->event($rep, 'task-'.$done->getKey());
        $cancelledEvent = $this->event($rep, 'task-'.$cancelled->getKey());

        $this->assertSame('var(--crm-color-success)', $completedEvent->backgroundColor);
        $this->assertSame([CalendarFeed::MUTED_CLASS], $completedEvent->classNames);
        $this->assertFalse($completedEvent->editable);
        $this->assertSame(TaskStatus::Completed->value, $completedEvent->extendedProps['status']);

        $this->assertSame('var(--crm-color-warning)', $cancelledEvent->backgroundColor);
        $this->assertSame([CalendarFeed::MUTED_CLASS], $cancelledEvent->classNames);
        $this->assertFalse($cancelledEvent->editable);
    }

    #[Test]
    public function a_task_the_viewer_may_only_read_is_not_editable(): void
    {
        $readOnly = $this->readOnly();
        $task = Task::factory()->create(['assignee_id' => $this->salesRep()->getKey(), 'due_at' => '2026-09-10 10:00:00']);

        $event = $this->event($readOnly, 'task-'.$task->getKey());

        $this->assertFalse($event->editable);
    }

    #[Test]
    public function meetings_and_calls_appear_as_activity_events_with_their_url(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $meeting = Activity::factory()->ofKind(ActivityKind::Meeting)->create([
            'lead_id' => $lead->getKey(),
            'owner_id' => $rep->getKey(),
            'created_by' => $rep->getKey(),
            'occurred_at' => '2026-09-12 14:00:00',
            'duration_minutes' => 45,
        ]);
        $call = Activity::factory()->ofKind(ActivityKind::Call)->create([
            'lead_id' => $lead->getKey(),
            'owner_id' => $rep->getKey(),
            'created_by' => $rep->getKey(),
            'occurred_at' => '2026-09-13 09:15:00',
            'duration_minutes' => null,
        ]);

        $meetingEvent = $this->event($rep, 'activity-'.$meeting->getKey());
        $callEvent = $this->event($rep, 'activity-'.$call->getKey());

        $this->assertSame($meeting->subject, $meetingEvent->title);
        $this->assertSame('2026-09-12T14:00:00+03:00', $meetingEvent->start);
        $this->assertSame('2026-09-12T14:45:00+03:00', $meetingEvent->end);
        $this->assertFalse($meetingEvent->allDay);
        $this->assertFalse($meetingEvent->editable);
        $this->assertSame('var(--crm-color-primary)', $meetingEvent->backgroundColor);
        $this->assertSame(ActivityResource::getUrl('view', ['record' => $meeting]), $meetingEvent->url);
        $this->assertSame('activity', $meetingEvent->extendedProps['type']);
        $this->assertSame(ActivityKind::Meeting->value, $meetingEvent->extendedProps['kind']);
        $this->assertNull($meetingEvent->extendedProps['status']);
        $this->assertSame($meeting->subjectLabel(), $meetingEvent->extendedProps['subject']);

        $this->assertSame('2026-09-13T09:15:00+03:00', $callEvent->start);
        $this->assertNull($callEvent->end);
        $this->assertSame('var(--crm-color-info)', $callEvent->backgroundColor);
        $this->assertSame(ActivityKind::Call->value, $callEvent->extendedProps['kind']);
    }

    #[Test]
    public function activities_of_other_kinds_are_left_out(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $excluded = [];

        foreach ([ActivityKind::Email, ActivityKind::Note, ActivityKind::Task, ActivityKind::System, ActivityKind::Other] as $kind) {
            $excluded[] = Activity::factory()->ofKind($kind)->create([
                'lead_id' => $lead->getKey(),
                'owner_id' => $rep->getKey(),
                'created_by' => $rep->getKey(),
                'occurred_at' => '2026-09-12 14:00:00',
            ]);
        }

        $ids = $this->ids($rep);

        foreach ($excluded as $activity) {
            $this->assertNotContains('activity-'.$activity->getKey(), $ids);
        }
    }

    #[Test]
    public function another_reps_activity_is_not_returned(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $theirs = Activity::factory()->ofKind(ActivityKind::Meeting)->create([
            'lead_id' => Lead::factory()->create(['owner_id' => $other->getKey()])->getKey(),
            'owner_id' => $other->getKey(),
            'created_by' => $other->getKey(),
            'occurred_at' => '2026-09-12 14:00:00',
        ]);

        $this->assertNotContains('activity-'.$theirs->getKey(), $this->ids($rep));
    }

    #[Test]
    public function entries_outside_the_range_are_left_out_and_the_rest_are_ordered_by_start(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $before = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => '2026-08-31 23:00:00']);
        $after = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => '2026-10-01 00:00:00']);
        $spanEndsBefore = Task::factory()->create(['assignee_id' => $rep->getKey(), 'kind' => TaskKind::Meeting, 'due_at' => '2026-08-30 10:00:00', 'starts_at' => '2026-08-30 10:00:00', 'ends_at' => '2026-08-30 11:00:00']);
        $spanCrossesStart = Task::factory()->create(['assignee_id' => $rep->getKey(), 'kind' => TaskKind::Meeting, 'due_at' => '2026-08-31 23:00:00', 'starts_at' => '2026-08-31 23:00:00', 'ends_at' => '2026-09-01 01:00:00']);
        $late = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => '2026-09-20 10:00:00']);
        $early = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => '2026-09-02 10:00:00']);
        $activityBefore = Activity::factory()->ofKind(ActivityKind::Call)->create(['lead_id' => $lead->getKey(), 'owner_id' => $rep->getKey(), 'created_by' => $rep->getKey(), 'occurred_at' => '2026-08-15 10:00:00']);
        $activityInside = Activity::factory()->ofKind(ActivityKind::Call)->create(['lead_id' => $lead->getKey(), 'owner_id' => $rep->getKey(), 'created_by' => $rep->getKey(), 'occurred_at' => '2026-09-10 10:00:00']);

        $ids = $this->ids($rep);

        $this->assertNotContains('task-'.$before->getKey(), $ids);
        $this->assertNotContains('task-'.$after->getKey(), $ids);
        $this->assertNotContains('task-'.$spanEndsBefore->getKey(), $ids);
        $this->assertNotContains('activity-'.$activityBefore->getKey(), $ids);
        $this->assertSame([
            'task-'.$spanCrossesStart->getKey(),
            'task-'.$early->getKey(),
            'activity-'.$activityInside->getKey(),
            'task-'.$late->getKey(),
        ], $ids);
    }

    #[Test]
    public function a_task_with_neither_a_due_date_nor_a_start_is_not_on_the_calendar(): void
    {
        $rep = $this->salesRep();
        $undated = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => null]);

        $this->assertNotContains('task-'.$undated->getKey(), $this->ids($rep));
    }

    #[Test]
    public function the_event_array_carries_the_fullcalendar_keys(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => '2026-09-10 10:00:00']);

        $array = $this->event($rep, 'task-'.$task->getKey())->toArray();

        $this->assertSame(
            ['id', 'title', 'start', 'end', 'allDay', 'backgroundColor', 'borderColor', 'textColor', 'url', 'editable', 'classNames', 'extendedProps'],
            array_keys($array),
        );
        $this->assertSame('var(--crm-color-on-event)', $array['textColor']);
        $this->assertSame(['type', 'status', 'priority', 'kind', 'subject'], array_keys($array['extendedProps']));
    }

    #[Test]
    public function the_range_is_read_in_the_organisation_timezone_and_the_query_and_the_display_agree(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        app(SettingsRepository::class)->update([SettingsRepository::TIMEZONE => 'Asia/Dubai'], $admin);
        // 23:30 in the application timezone (Asia/Riyadh, +03:00) is 00:30 the next day in Dubai (+04:00).
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => '2026-09-30 23:30:00']);
        $this->actingAs($rep);
        $feed = app(CalendarFeed::class);

        $october = $feed->events($rep, Carbon::parse('2026-10-01 00:00:00', 'Asia/Dubai'), Carbon::parse('2026-11-01 00:00:00', 'Asia/Dubai'));
        $september = $feed->events($rep, Carbon::parse('2026-09-01 00:00:00', 'Asia/Dubai'), Carbon::parse('2026-10-01 00:00:00', 'Asia/Dubai'));

        $event = $october->first(static fn (CalendarEvent $event): bool => $event->id === 'task-'.$task->getKey());
        $this->assertInstanceOf(CalendarEvent::class, $event);
        $this->assertTrue($event->allDay);
        $this->assertSame('2026-10-01', $event->start);
        $this->assertNotContains('task-'.$task->getKey(), $september->map(static fn (CalendarEvent $event): string => $event->id)->all());
    }

    #[Test]
    public function the_default_queries_are_refused_for_a_viewer_who_is_not_the_authenticated_user(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $this->actingAs($rep);

        $this->expectException(LogicException::class);

        app(CalendarFeed::class)->events($other, Carbon::parse('2026-09-01'), Carbon::parse('2026-10-01'));
    }

    /**
     * The viewer's September entries, resolved the way the page does it —
     * authenticated as the viewer, over the resources' scoped queries.
     *
     * @return Collection<int, CalendarEvent>
     */
    private function events(User $viewer): Collection
    {
        $this->actingAs($viewer);

        return app(CalendarFeed::class)->events($viewer, Carbon::parse('2026-09-01 00:00:00'), Carbon::parse('2026-10-01 00:00:00'));
    }

    /**
     * @return list<string>
     */
    private function ids(User $viewer): array
    {
        return $this->events($viewer)->map(static fn (CalendarEvent $event): string => $event->id)->all();
    }

    private function event(User $viewer, string $id): CalendarEvent
    {
        $event = $this->events($viewer)->first(static fn (CalendarEvent $event): bool => $event->id === $id);

        $this->assertInstanceOf(CalendarEvent::class, $event, "event {$id} missing from the feed");

        return $event;
    }
}
