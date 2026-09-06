<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityKind;
use App\Enums\ActivityLogEvent;
use App\Enums\RecurrenceFrequency;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\Access\UnassignableUserException;
use App\Exceptions\Tasks\InvalidTaskTransitionException;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Notifications\RecordAssignedNotification;
use App\Notifications\TaskOverdueNotification;
use App\Notifications\TaskReminderNotification;
use App\Services\Tasks\TaskReminderService;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * TaskService (decisions A-10, D-4): creation defaults, the status
 * transitions with their audit rows and the timeline entry a completion
 * writes on the linked record, the workflow guard and rescheduling.
 */
final class TaskServiceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    #[Test]
    public function creating_defaults_the_assignee_to_the_actor_and_starts_pending(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $task = $this->service()->create([
            'title' => '  Call back about the quote  ',
            'due_at' => '2026-09-10 10:00:00',
            'lead_id' => $lead->getKey(),
        ], $rep);

        $this->assertSame('Call back about the quote', $task->title);
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertSame(TaskKind::Task, $task->kind);
        $this->assertSame(TaskPriority::Medium, $task->priority);
        $this->assertSame(RecurrenceFrequency::None, $task->recurrence_frequency);
        $this->assertNull($task->recurrence_interval);
        $this->assertSame($rep->getKey(), (int) $task->assignee_id);
        $this->assertSame($rep->getKey(), (int) $task->created_by);
        $this->assertSame($lead->getKey(), (int) $task->lead_id);
        $this->assertNull($task->reminder_at);
        $this->assertSame('2026-09-10 10:00:00', $task->due_at?->format('Y-m-d H:i:s'));
        $this->assertTrue($task->isOpen());
        $this->assertTrue($rep->is($task->owner));
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskCreated->value, 'subject_id' => $task->getKey(), 'causer_id' => $rep->getKey()]);
    }

    #[Test]
    public function a_task_may_exist_without_any_linked_record(): void
    {
        $rep = $this->salesRep();

        $task = $this->service()->create(['title' => 'Tidy the pipeline'], $rep);

        $this->assertNull($task->subjectRecord());
        $this->assertNull($task->subjectLabel());
        $this->assertSame([], $task->linkedRecords());
    }

    #[Test]
    public function a_start_and_an_end_are_kept_for_meetings_and_calls_only(): void
    {
        $rep = $this->salesRep();

        $meeting = $this->service()->create([
            'title' => 'Kick-off',
            'kind' => TaskKind::Meeting->value,
            'starts_at' => '2026-09-10 10:00:00',
            'ends_at' => '2026-09-10 11:00:00',
        ], $rep);

        $plain = $this->service()->create([
            'title' => 'Prepare the deck',
            'kind' => TaskKind::Task->value,
            'starts_at' => '2026-09-10 10:00:00',
            'ends_at' => '2026-09-10 11:00:00',
        ], $rep);

        $this->assertSame('2026-09-10 10:00:00', $meeting->starts_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-10 11:00:00', $meeting->ends_at?->format('Y-m-d H:i:s'));
        $this->assertNull($plain->starts_at);
        $this->assertNull($plain->ends_at);
    }

    #[Test]
    public function an_end_before_the_start_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->create([
            'title' => 'Backwards meeting',
            'kind' => TaskKind::Call->value,
            'starts_at' => '2026-09-10 11:00:00',
            'ends_at' => '2026-09-10 10:00:00',
        ], $this->salesRep());
    }

    #[Test]
    public function recurrence_settings_are_normalised(): void
    {
        $rep = $this->salesRep();

        $repeating = $this->service()->create([
            'title' => 'Weekly check-in',
            'due_at' => '2026-09-10 10:00:00',
            'recurrence_frequency' => 'weekly',
            'recurrence_interval' => 0,
            'recurrence_ends_at' => '2026-12-31',
        ], $rep);

        $this->assertSame(RecurrenceFrequency::Weekly, $repeating->recurrence_frequency);
        $this->assertSame(1, (int) $repeating->recurrence_interval);
        $this->assertSame('2026-12-31', $repeating->recurrence_ends_at?->toDateString());

        $once = $this->service()->update($repeating, ['recurrence_frequency' => 'none'], $rep);

        $this->assertSame(RecurrenceFrequency::None, $once->recurrence_frequency);
        $this->assertNull($once->recurrence_interval);
        $this->assertNull($once->recurrence_ends_at);
    }

    #[Test]
    public function updating_changes_the_given_attributes_and_maps_the_owner_alias_to_the_assignee(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $task = Task::factory()->create(['assignee_id' => $manager->getKey(), 'title' => 'Draft']);

        $updated = $this->service()->update($task, ['title' => 'Final', 'owner_id' => $rep->getKey(), 'priority' => 'urgent'], $manager);

        $this->assertSame('Final', $updated->title);
        $this->assertSame(TaskPriority::Urgent, $updated->priority);
        $this->assertSame($rep->getKey(), (int) $updated->assignee_id);
        $this->assertSame(TaskStatus::Pending, $updated->status);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskUpdated->value, 'subject_id' => $task->getKey(), 'causer_id' => $manager->getKey()]);
    }

    #[Test]
    public function completing_stamps_audits_and_writes_a_system_activity_on_the_lead(): void
    {
        $this->travelTo(Carbon::parse('2026-09-06 14:30:00'));

        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey(), 'last_activity_at' => null]);
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'lead_id' => $lead->getKey(), 'title' => 'Send the brochure']);

        $completed = $this->service()->complete($task, $rep, 'Sent by email');

        $this->assertSame(TaskStatus::Completed, $completed->status);
        $this->assertSame('2026-09-06 14:30:00', $completed->completed_at?->format('Y-m-d H:i:s'));
        $this->assertFalse($completed->isOpen());
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskCompleted->value, 'subject_id' => $task->getKey(), 'causer_id' => $rep->getKey()]);

        $activity = Activity::query()->where('task_id', $task->getKey())->firstOrFail();

        $this->assertSame(ActivityKind::Task, $activity->kind);
        $this->assertSame($lead->getKey(), (int) $activity->lead_id);
        $this->assertSame('Send the brochure', $activity->subject);
        $this->assertSame($rep->getKey(), (int) $activity->created_by);
        $this->assertSame($task->getKey(), (int) ($activity->payload['task_id'] ?? 0));
        $this->assertSame('Sent by email', $activity->payload['note'] ?? null);
        $this->assertTrue($activity->task?->is($task));
        $this->assertSame('2026-09-06 14:30:00', $lead->refresh()->last_activity_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function completing_a_deal_task_links_the_activity_to_the_deal_and_its_account(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'deal_id' => $deal->getKey()]);

        $this->service()->complete($task, $rep);

        $activity = Activity::query()->where('task_id', $task->getKey())->firstOrFail();

        $this->assertSame($deal->getKey(), (int) $activity->deal_id);
        $this->assertSame((int) $deal->account_id, (int) $activity->account_id);
        $this->assertNotNull($deal->refresh()->last_activity_at);
    }

    #[Test]
    public function completing_a_task_without_a_linked_record_writes_no_activity(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey()]);

        $this->service()->complete($task, $rep);

        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
        $this->assertSame(0, Activity::query()->count());
    }

    #[Test]
    public function completing_a_completed_task_is_refused(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->completed()->create(['assignee_id' => $rep->getKey()]);

        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);

        $this->expectException(InvalidTaskTransitionException::class);

        $this->service()->complete($task, $rep);
    }

    #[Test]
    public function cancelling_and_reopening_transition_and_are_audited(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey()]);

        $cancelled = $this->service()->cancel($task, $rep);

        $this->assertSame(TaskStatus::Cancelled, $cancelled->status);
        $this->assertNull($cancelled->completed_at);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskCancelled->value, 'subject_id' => $task->getKey(), 'causer_id' => $rep->getKey()]);

        $reopened = $this->service()->reopen($task, $rep);

        $this->assertSame(TaskStatus::Pending, $reopened->status);
        $this->assertTrue($reopened->isOpen());
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskReopened->value, 'subject_id' => $task->getKey(), 'causer_id' => $rep->getKey()]);
    }

    #[Test]
    public function reopening_a_completed_task_clears_the_completion_stamp(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey()]);

        $this->service()->complete($task, $rep);
        $this->assertNotNull($task->refresh()->completed_at);

        $this->service()->reopen($task, $rep);

        $this->assertSame(TaskStatus::Pending, $task->refresh()->status);
        $this->assertNull($task->completed_at);
    }

    #[Test]
    public function cancelling_a_closed_task_and_reopening_an_open_one_are_refused(): void
    {
        $rep = $this->salesRep();
        $open = Task::factory()->create(['assignee_id' => $rep->getKey()]);
        $cancelled = Task::factory()->cancelled()->create(['assignee_id' => $rep->getKey()]);

        try {
            $this->service()->reopen($open, $rep);
            $this->fail('An open task must not be reopened.');
        } catch (InvalidTaskTransitionException $exception) {
            $this->assertSame(__('tasks.validation.not_closed'), $exception->getMessage());
        }

        try {
            $this->service()->cancel($cancelled, $rep);
            $this->fail('A cancelled task must not be cancelled again.');
        } catch (InvalidTaskTransitionException $exception) {
            $this->assertSame(__('tasks.validation.not_open'), $exception->getMessage());
        }

        $this->assertSame(TaskStatus::Pending, $open->refresh()->status);
        $this->assertSame(TaskStatus::Cancelled, $cancelled->refresh()->status);
    }

    #[Test]
    public function the_guarded_columns_cannot_be_written_outside_the_services(): void
    {
        $task = Task::factory()->create();

        try {
            $task->status = TaskStatus::Completed;
            $task->save();
            $this->fail('status must be guarded.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('status', $exception->getMessage());
        }

        $fresh = $task->fresh();
        $this->assertNotNull($fresh);

        foreach (['completed_at', 'reminder_sent_at', 'overdue_notified_at'] as $column) {
            try {
                $fresh->forceFill([$column => now()])->save();
                $this->fail($column.' must be guarded.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString($column, $exception->getMessage());
            }
        }

        $this->assertSame(TaskStatus::Pending, $task->refresh()->status);
        $this->assertNull($task->completed_at);
    }

    #[Test]
    public function rescheduling_moves_the_dates_and_is_audited_as_an_update(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create([
            'assignee_id' => $rep->getKey(),
            'kind' => TaskKind::Meeting,
            'due_at' => Carbon::parse('2026-09-10 10:00:00'),
            'starts_at' => Carbon::parse('2026-09-10 10:00:00'),
            'ends_at' => Carbon::parse('2026-09-10 11:00:00'),
        ]);

        $moved = $this->service()->reschedule(
            $task,
            Carbon::parse('2026-09-12 15:00:00'),
            Carbon::parse('2026-09-12 15:00:00'),
            Carbon::parse('2026-09-12 16:30:00'),
            $rep,
        );

        $this->assertSame('2026-09-12 15:00:00', $moved->due_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-12 15:00:00', $moved->starts_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-12 16:30:00', $moved->ends_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskUpdated->value, 'subject_id' => $task->getKey(), 'causer_id' => $rep->getKey()]);

        $this->expectException(ValidationException::class);

        $this->service()->reschedule($task, Carbon::parse('2026-09-13 10:00:00'), Carbon::parse('2026-09-13 10:00:00'), Carbon::parse('2026-09-13 09:00:00'), $rep);
    }

    #[Test]
    public function overdue_and_due_today_read_the_status_and_the_due_date(): void
    {
        $this->travelTo(Carbon::parse('2026-09-06 12:00:00'));

        $overdue = Task::factory()->create(['due_at' => Carbon::parse('2026-09-05 09:00:00')]);
        $today = Task::factory()->create(['due_at' => Carbon::parse('2026-09-06 18:00:00')]);
        $later = Task::factory()->create(['due_at' => Carbon::parse('2026-09-08 09:00:00')]);
        $closed = Task::factory()->completed()->create(['due_at' => Carbon::parse('2026-09-05 09:00:00')]);
        $undated = Task::factory()->create(['due_at' => null]);

        $this->assertTrue($overdue->isOverdue());
        $this->assertFalse($overdue->isDueToday());
        $this->assertTrue($today->isDueToday());
        $this->assertFalse($today->isOverdue());
        $this->assertFalse($later->isOverdue());
        $this->assertFalse($later->isDueToday());
        $this->assertFalse($closed->refresh()->isOverdue());
        $this->assertFalse($undated->isOverdue());

        $this->assertSame([$overdue->getKey(), $today->getKey()], Task::query()->open()->dueBetween(Carbon::parse('2026-09-05 00:00:00'), Carbon::parse('2026-09-06 23:59:59'))->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    #[Test]
    public function changing_the_assignee_on_edit_goes_through_the_assignment_service(): void
    {
        Notification::fake();

        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $task = Task::factory()->create(['assignee_id' => $manager->getKey()]);

        $this->service()->update($task, ['owner_id' => $rep->getKey()], $manager);

        $this->assertSame($rep->getKey(), (int) $task->refresh()->assignee_id);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskAssigned->value, 'subject_id' => $task->getKey(), 'causer_id' => $manager->getKey()]);

        Notification::assertSentTo($rep, RecordAssignedNotification::class);

        // An edit that keeps the assignee writes no assignment row.
        $this->service()->update($task, ['title' => 'Same assignee'], $manager);

        $this->assertSame(1, DB::table('activity_log')->where('description', ActivityLogEvent::TaskAssigned->value)->where('subject_id', $task->getKey())->count());
        Notification::assertSentToTimes($rep, RecordAssignedNotification::class, 1);
    }

    #[Test]
    public function an_assignee_outside_the_actor_reach_is_refused_on_edit(): void
    {
        $manager = $this->salesManager($this->makeTeam());
        $stranger = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));
        $task = Task::factory()->create(['assignee_id' => $manager->getKey(), 'title' => 'Mine']);

        $this->expectException(UnassignableUserException::class);

        try {
            $this->service()->update($task, ['title' => 'Changed', 'owner_id' => $stranger->getKey()], $manager);
        } finally {
            // The whole edit is rolled back with the refused assignment.
            $this->assertSame('Mine', $task->refresh()->title);
            $this->assertSame($manager->getKey(), (int) $task->assignee_id);
        }
    }

    #[Test]
    public function moving_the_reminder_after_it_was_sent_re_arms_it(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-09-06 09:00:00'));

        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'reminder_at' => Carbon::parse('2026-09-06 08:30:00')]);

        $this->assertSame(1, $this->reminders()->sendDueReminders(now()));
        $this->assertNotNull($task->refresh()->reminder_sent_at);

        // An edit that leaves the reminder alone keeps the stamp.
        $this->service()->update($task, ['title' => 'Renamed'], $rep);
        $this->assertNotNull($task->refresh()->reminder_sent_at);

        $this->service()->update($task, ['reminder_at' => '2026-09-13 08:30:00'], $rep);

        $this->assertNull($task->refresh()->reminder_sent_at);
        $this->assertSame(0, $this->reminders()->sendDueReminders(now()));

        $this->travelTo(Carbon::parse('2026-09-13 09:00:00'));

        $this->assertSame(1, $this->reminders()->sendDueReminders(now()));
        $this->assertSame('2026-09-13 09:00:00', Task::query()->findOrFail($task->getKey())->reminder_sent_at?->format('Y-m-d H:i:s'));
        Notification::assertSentToTimes($rep, TaskReminderNotification::class, 2);
    }

    #[Test]
    public function rescheduling_an_overdue_task_to_a_later_date_re_arms_the_overdue_notice(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-09-06 09:00:00'));

        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => Carbon::parse('2026-09-05 17:00:00')]);

        $this->assertSame(1, $this->reminders()->notifyOverdue(now()));
        $this->assertNotNull($task->refresh()->overdue_notified_at);

        // Moving the due date earlier keeps the task overdue: no second notice.
        $this->service()->reschedule($task, Carbon::parse('2026-09-04 17:00:00'), null, null, $rep);
        $this->assertNotNull($task->refresh()->overdue_notified_at);

        $this->service()->reschedule($task, Carbon::parse('2026-10-06 17:00:00'), null, null, $rep);

        $this->assertNull($task->refresh()->overdue_notified_at);
        $this->assertSame(0, $this->reminders()->notifyOverdue(now()));

        $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));

        $this->assertSame(1, $this->reminders()->notifyOverdue(now()));
        Notification::assertSentToTimes($rep, TaskOverdueNotification::class, 2);

        // The same re-arming applies to a due date changed through an edit.
        $this->service()->update($task, ['due_at' => '2026-11-06 17:00:00'], $rep);
        $this->assertNull($task->refresh()->overdue_notified_at);
    }

    #[Test]
    public function reopening_re_arms_both_notification_stamps(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-09-06 09:00:00'));

        $rep = $this->salesRep();
        $task = Task::factory()->create([
            'assignee_id' => $rep->getKey(),
            'due_at' => Carbon::parse('2026-09-05 17:00:00'),
            'reminder_at' => Carbon::parse('2026-09-05 16:00:00'),
        ]);

        $this->reminders()->sendDueReminders(now());
        $this->reminders()->notifyOverdue(now());
        $this->service()->complete($task, $rep);

        $this->assertNotNull($task->refresh()->reminder_sent_at);
        $this->assertNotNull($task->overdue_notified_at);

        $this->service()->reopen($task, $rep);

        $this->assertNull($task->refresh()->reminder_sent_at);
        $this->assertNull($task->overdue_notified_at);
        $this->assertSame(1, $this->reminders()->sendDueReminders(now()));
        $this->assertSame(1, $this->reminders()->notifyOverdue(now()));
    }

    #[Test]
    public function a_deleted_task_has_no_transitions_until_it_is_restored(): void
    {
        $rep = $this->salesRep();
        $open = Task::factory()->create(['assignee_id' => $rep->getKey()]);
        $closed = Task::factory()->completed()->create(['assignee_id' => $rep->getKey()]);
        $open->delete();
        $closed->delete();

        foreach ([
            fn (): Task => $this->service()->complete($open, $rep),
            fn (): Task => $this->service()->cancel($open, $rep),
            fn (): Task => $this->service()->reopen($closed, $rep),
        ] as $transition) {
            try {
                $transition();
                $this->fail('A deleted task must not change status.');
            } catch (InvalidTaskTransitionException $exception) {
                $this->assertSame(__('tasks.validation.trashed'), $exception->getMessage());
            }
        }

        $this->assertSame(TaskStatus::Pending, $open->refresh()->status);
        $this->assertSame(TaskStatus::Completed, $closed->refresh()->status);

        $open->restore();

        $this->assertSame(TaskStatus::Completed, $this->service()->complete($open, $rep)->status);
    }

    private function service(): TaskService
    {
        return app(TaskService::class);
    }

    private function reminders(): TaskReminderService
    {
        return app(TaskReminderService::class);
    }
}
