<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\RecurrenceFrequency;
use App\Enums\RoutineFrequency;
use App\Models\Task;
use App\Services\Access\RecordAssignmentService;
use App\Services\Tasks\RoutineTaskService;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * `tasks.assigned_at` (decision D-21): when the task reached its current
 * assignee, kept by TaskAssignmentStampObserver on every path that saves a
 * task — stamped on a create with an assignee, restamped on reassignment,
 * cleared on unassignment, and moved by nothing else.
 */
final class TaskAssignmentStampTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-01 09:00:00'));
    }

    #[Test]
    public function a_task_created_through_the_service_is_stamped_when_it_gets_an_assignee(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();

        $own = app(TaskService::class)->create(['title' => 'My own follow-up'], $rep);
        $handedOut = app(TaskService::class)->create(['title' => 'For the rep', 'assignee_id' => $rep->getKey()], $admin);
        $unassigned = app(TaskService::class)->create(['title' => 'Nobody yet', 'assignee_id' => null], $admin);

        $this->assertSame('2026-10-01 09:00:00', $own->refresh()->assigned_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 09:00:00', $handedOut->refresh()->assigned_at?->format('Y-m-d H:i:s'));
        $this->assertNull($unassigned->refresh()->assigned_at);
    }

    #[Test]
    public function an_edit_that_changes_the_assignee_restamps_and_any_other_edit_leaves_the_stamp_alone(): void
    {
        $admin = $this->admin();
        $repA = $this->salesRep();
        $repB = $this->salesRep();
        $task = app(TaskService::class)->create(['title' => 'Quotation', 'assignee_id' => $repA->getKey()], $admin);

        $this->travelTo(Carbon::parse('2026-10-02 11:30:00'));
        app(TaskService::class)->update($task, ['title' => 'Quotation v2', 'due_at' => '2026-10-09 10:00:00'], $admin);

        $this->assertSame('2026-10-01 09:00:00', $task->refresh()->assigned_at?->format('Y-m-d H:i:s'));

        $this->travelTo(Carbon::parse('2026-10-03 14:15:00'));
        app(TaskService::class)->update($task, ['assignee_id' => $repB->getKey()], $admin);

        $this->assertSame($repB->getKey(), (int) $task->refresh()->assignee_id);
        $this->assertSame('2026-10-03 14:15:00', $task->assigned_at?->format('Y-m-d H:i:s'));

        $this->travelTo(Carbon::parse('2026-10-04 08:00:00'));
        app(TaskService::class)->update($task, ['assignee_id' => null], $admin);

        $this->assertNull($task->refresh()->assignee_id);
        $this->assertNull($task->assigned_at);
    }

    #[Test]
    public function the_assignment_service_restamps_on_reassignment_and_clears_on_unassignment(): void
    {
        $admin = $this->admin();
        $repA = $this->salesRep();
        $repB = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $repA->getKey()]);

        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        app(RecordAssignmentService::class)->assign($task, $repB, $admin);

        $this->assertSame('2026-10-05 10:00:00', $task->refresh()->assigned_at?->format('Y-m-d H:i:s'));

        // Handing the task to the assignee it already has is no change.
        $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));
        app(RecordAssignmentService::class)->assign($task, $repB, $admin);

        $this->assertSame('2026-10-05 10:00:00', $task->refresh()->assigned_at?->format('Y-m-d H:i:s'));

        app(RecordAssignmentService::class)->assign($task, null, $admin);

        $this->assertNull($task->refresh()->assigned_at);

        $this->travelTo(Carbon::parse('2026-10-07 16:45:00'));
        app(RecordAssignmentService::class)->assign($task, $repA, $admin);

        $this->assertSame('2026-10-07 16:45:00', $task->refresh()->assigned_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_next_occurrence_of_a_recurring_task_is_stamped_when_it_is_spawned(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()
            ->recurring(RecurrenceFrequency::Weekly)
            ->create(['assignee_id' => $rep->getKey(), 'due_at' => Carbon::parse('2026-10-01 12:00:00')]);

        $this->travelTo(Carbon::parse('2026-10-02 17:20:00'));
        app(TaskService::class)->complete($task, $rep);

        $next = Task::query()->where('series_id', $task->getKey())->sole();

        $this->assertSame($rep->getKey(), (int) $next->assignee_id);
        $this->assertSame('2026-10-02 17:20:00', $next->assigned_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 09:00:00', $task->refresh()->assigned_at?->format('Y-m-d H:i:s'), 'completing the first occurrence leaves its own stamp alone');
    }

    #[Test]
    public function a_routine_task_is_stamped_when_the_routine_hands_it_out(): void
    {
        $admin = $this->admin();
        $employee = $this->employee();
        $routines = app(RoutineTaskService::class);

        // 1 Oct 2026 is a Thursday (weekday 4): the routine's day is today.
        $routine = $routines->save([
            'title' => 'Weekly stock count',
            'frequency' => RoutineFrequency::Weekly->value,
            'weekday' => 4,
            'assignees' => [$employee->getKey()],
        ], $admin);

        $this->travelTo(Carbon::parse('2026-10-01 10:00:00'));
        $this->assertSame(1, $routines->generateDue());

        $task = Task::query()->where('template_id', $routine->getKey())->sole();

        $this->assertSame($employee->getKey(), (int) $task->assignee_id);
        $this->assertSame('2026-10-01 10:00:00', $task->assigned_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_factories_stamp_an_assigned_task_and_leave_an_unassigned_one_empty(): void
    {
        $assigned = Task::factory()->create();
        $unassigned = Task::factory()->unassigned()->create();

        $this->assertSame('2026-10-01 09:00:00', $assigned->assigned_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 09:00:00', $assigned->refresh()->assigned_at?->format('Y-m-d H:i:s'));
        $this->assertNull($unassigned->assigned_at);
        $this->assertNull($unassigned->refresh()->assigned_at);
    }

    #[Test]
    public function the_status_transitions_leave_the_stamp_alone(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $task = app(TaskService::class)->create(['title' => 'Site visit', 'assignee_id' => $rep->getKey()], $admin);
        $service = app(TaskService::class);

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $service->start($task, $rep);
        $service->postUpdate($task, $rep, 'Booked the visit.');

        $this->travelTo(Carbon::parse('2026-10-03 09:00:00'));
        $service->complete($task, $rep);
        $service->reopen($task, $rep);
        $service->reschedule($task, Carbon::parse('2026-10-10 10:00:00'), null, null, $admin);

        $this->assertSame('2026-10-01 09:00:00', $task->refresh()->assigned_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function nothing_but_a_change_of_assignee_moves_the_stamp(): void
    {
        $rep = $this->salesRep();

        // A value handed to a new task is replaced by the moment of creation.
        $task = Task::factory()->create(['title' => 'Backdated', 'assignee_id' => $rep->getKey(), 'assigned_at' => Carbon::parse('2020-01-01 00:00:00')]);

        $this->assertSame('2026-10-01 09:00:00', $task->refresh()->assigned_at?->format('Y-m-d H:i:s'));

        // A value written on its own to a saved task is not kept.
        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $task->forceFill(['assigned_at' => Carbon::parse('2020-01-01 00:00:00'), 'title' => 'Backdated again'])->save();

        $this->assertSame('Backdated again', $task->refresh()->title);
        $this->assertSame('2026-10-01 09:00:00', $task->assigned_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_stamp_is_not_written_to_the_audit_ledger(): void
    {
        $admin = $this->admin();
        $repA = $this->salesRep();
        $repB = $this->salesRep();
        $task = app(TaskService::class)->create(['title' => 'Audit me', 'assignee_id' => $repA->getKey()], $admin);

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        app(TaskService::class)->update($task, ['assignee_id' => $repB->getKey()], $admin);

        $entries = DB::table('activity_log')
            ->where('subject_type', $task->getMorphClass())
            ->where('subject_id', $task->getKey());

        $this->assertTrue((clone $entries)->where('properties', 'like', '%assignee_id%')->exists(), 'the reassignment itself is audited');
        $this->assertFalse((clone $entries)->where('properties', 'like', '%assigned_at%')->exists(), 'assigned_at is bookkeeping for the report, outside the LogsActivity whitelist');
    }
}
