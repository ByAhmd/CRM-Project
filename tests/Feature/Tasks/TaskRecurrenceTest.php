<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\RecurrenceFrequency;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Deal;
use App\Models\Task;
use App\Services\Tasks\TaskRecurrence;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Recurring tasks (decision A-10): completing an occurrence schedules the
 * next one — daily, weekly, monthly without overflow — as a copy in the
 * same series, until the end date; a task that does not repeat spawns
 * nothing.
 */
final class TaskRecurrenceTest extends TestCase
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
    public function completing_a_daily_task_with_interval_two_creates_the_next_occurrence_two_days_later(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $task = Task::factory()
            ->recurring(RecurrenceFrequency::Daily, 2)
            ->create([
                'assignee_id' => $rep->getKey(),
                'deal_id' => $deal->getKey(),
                'title' => 'Stand-up',
                'description' => 'Ten minutes',
                'kind' => TaskKind::Call,
                'priority' => TaskPriority::High,
                'due_at' => Carbon::parse('2026-09-06 09:00:00'),
                'starts_at' => Carbon::parse('2026-09-06 09:00:00'),
                'ends_at' => Carbon::parse('2026-09-06 09:15:00'),
                'reminder_at' => Carbon::parse('2026-09-06 08:00:00'),
            ]);

        $this->service()->complete($task, $rep);

        $next = Task::query()->where('series_id', $task->getKey())->firstOrFail();

        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
        $this->assertSame(TaskStatus::Pending, $next->status);
        $this->assertSame('2026-09-08 09:00:00', $next->due_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-08 09:00:00', $next->starts_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-08 09:15:00', $next->ends_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-08 08:00:00', $next->reminder_at?->format('Y-m-d H:i:s'));
        $this->assertNull($next->reminder_sent_at);
        $this->assertNull($next->overdue_notified_at);
        $this->assertNull($next->completed_at);
        $this->assertSame('Stand-up', $next->title);
        $this->assertSame('Ten minutes', $next->description);
        $this->assertSame(TaskKind::Call, $next->kind);
        $this->assertSame(TaskPriority::High, $next->priority);
        $this->assertSame($rep->getKey(), (int) $next->assignee_id);
        $this->assertSame($deal->getKey(), (int) $next->deal_id);
        $this->assertSame(RecurrenceFrequency::Daily, $next->recurrence_frequency);
        $this->assertSame(2, (int) $next->recurrence_interval);
        $this->assertSame($task->getKey(), (int) $next->series_id);
        $this->assertSame((int) $task->created_by, (int) $next->created_by);
        $this->assertTrue($task->occurrences->first()?->is($next));
        $this->assertTrue($next->series?->is($task));
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskCreated->value, 'subject_id' => $next->getKey(), 'causer_id' => $rep->getKey()]);

        // The third occurrence still points at the first task of the series.
        $this->service()->complete($next, $rep);

        $third = Task::query()->where('series_id', $task->getKey())->where('id', '!=', $next->getKey())->firstOrFail();

        $this->assertSame('2026-09-10 09:00:00', $third->due_at?->format('Y-m-d H:i:s'));
        $this->assertSame(2, $task->occurrences()->count());
    }

    #[Test]
    public function a_weekly_task_moves_a_week_and_a_task_without_a_due_date_never_repeats(): void
    {
        $rep = $this->salesRep();
        $weekly = Task::factory()->recurring(RecurrenceFrequency::Weekly)->create([
            'assignee_id' => $rep->getKey(),
            'due_at' => Carbon::parse('2026-09-07 10:00:00'),
        ]);
        $undated = Task::factory()->recurring(RecurrenceFrequency::Weekly)->create([
            'assignee_id' => $rep->getKey(),
            'due_at' => null,
        ]);

        $this->service()->complete($weekly, $rep);
        $this->service()->complete($undated, $rep);

        $this->assertSame('2026-09-14 10:00:00', Task::query()->where('series_id', $weekly->getKey())->firstOrFail()->due_at?->format('Y-m-d H:i:s'));
        $this->assertSame(0, Task::query()->where('series_id', $undated->getKey())->count());
    }

    #[Test]
    public function a_monthly_task_due_on_the_31st_lands_on_the_last_day_of_february(): void
    {
        $rep = $this->salesRep();
        $common = Task::factory()->recurring(RecurrenceFrequency::Monthly)->create([
            'assignee_id' => $rep->getKey(),
            'due_at' => Carbon::parse('2026-01-31 09:00:00'),
            'reminder_at' => Carbon::parse('2026-01-30 09:00:00'),
        ]);
        $leap = Task::factory()->recurring(RecurrenceFrequency::Monthly)->create([
            'assignee_id' => $rep->getKey(),
            'due_at' => Carbon::parse('2028-01-31 09:00:00'),
        ]);

        $this->service()->complete($common, $rep);
        $this->service()->complete($leap, $rep);

        $nextCommon = Task::query()->where('series_id', $common->getKey())->firstOrFail();
        $nextLeap = Task::query()->where('series_id', $leap->getKey())->firstOrFail();

        $this->assertSame('2026-02-28 09:00:00', $nextCommon->due_at?->format('Y-m-d H:i:s'));
        // The reminder keeps its distance from the due date (one day before).
        $this->assertSame('2026-02-27 09:00:00', $nextCommon->reminder_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2028-02-29 09:00:00', $nextLeap->due_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_series_stops_once_the_next_due_date_passes_the_end_date(): void
    {
        $rep = $this->salesRep();
        $lastAllowed = Task::factory()
            ->recurring(RecurrenceFrequency::Daily, 1, Carbon::parse('2026-09-07'))
            ->create(['assignee_id' => $rep->getKey(), 'due_at' => Carbon::parse('2026-09-06 09:00:00')]);

        $this->service()->complete($lastAllowed, $rep);

        $next = Task::query()->where('series_id', $lastAllowed->getKey())->firstOrFail();

        $this->assertSame('2026-09-07 09:00:00', $next->due_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-07', $next->recurrence_ends_at?->toDateString());

        $this->service()->complete($next, $rep);

        $this->assertSame(1, Task::query()->where('series_id', $lastAllowed->getKey())->count());
        $this->assertNull(app(TaskRecurrence::class)->next($next->refresh()));
    }

    #[Test]
    public function a_task_that_does_not_repeat_creates_nothing(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => Carbon::parse('2026-09-06 09:00:00')]);

        $this->assertNull(app(TaskRecurrence::class)->next($task));

        $this->service()->complete($task, $rep);

        $this->assertSame(1, Task::query()->count());
        $this->assertSame(0, Task::query()->whereNotNull('series_id')->count());
    }

    private function service(): TaskService
    {
        return app(TaskService::class);
    }
}
