<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\RecurrenceFrequency;
use App\Enums\RoutineFrequency;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Notifications\RecordAssignedNotification;
use App\Notifications\TaskCompletedNotification;
use App\Services\Settings\SettingsRepository;
use App\Services\Tasks\RoutineTaskService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsRoutineTasks;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Decision D-22: on each scheduled day the CRM creates one task per assignee
 * — an ordinary handed-out task in the name of whoever last saved the
 * routine, linked back to it — and tells each assignee exactly as for any
 * assignment (D-14). The hourly run is idempotent, replays no backlog,
 * skips paused routines and assignees who may no longer sign in or open
 * tasks, and reads "today" in the organisation timezone. Deleting a routine
 * keeps its tasks. 2026-10-04 is a Sunday.
 */
final class RoutineTaskGenerationTest extends TestCase
{
    use BuildsRoutineTasks;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
        $this->travelToRiyadh('2026-10-04 08:00');
    }

    #[Test]
    public function each_assignee_gets_one_handed_out_task_linked_to_the_routine(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $employee = $this->employee();
        $routine = $this->routine($admin, [
            'title' => 'Weekly stock count',
            'description' => 'Count the shelves and post the totals.',
            'priority' => TaskPriority::High->value,
            'due_time' => '09:30',
            'due_in_days' => 2,
        ], $rep, $employee);

        $this->assertSame(2, app(RoutineTaskService::class)->generateDue());

        $tasks = Task::query()->where('template_id', $routine->getKey())->orderBy('assignee_id')->get();
        $this->assertCount(2, $tasks);
        $this->assertSame([$rep->getKey(), $employee->getKey()], $tasks->pluck('assignee_id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all());

        foreach ($tasks as $task) {
            $this->assertSame('Weekly stock count', $task->title);
            $this->assertSame('Count the shelves and post the totals.', $task->description);
            $this->assertSame(TaskKind::Task, $task->kind);
            $this->assertSame(TaskStatus::Pending, $task->status);
            $this->assertSame(TaskPriority::High, $task->priority);
            $this->assertSame(RecurrenceFrequency::None, $task->recurrence_frequency);
            $this->assertSame($admin->getKey(), $task->assigned_by);
            $this->assertSame($admin->getKey(), (int) $task->created_by);
            $this->assertSame($routine->getKey(), $task->template_id);
            $this->assertTrue($task->due_at?->equalTo(CarbonImmutable::parse('2026-10-06 09:30', 'Asia/Riyadh')), 'due two days later at 09:30 organisation time');
        }

        // An ordinary handed-out task (D-14 amendment): the assignee reports progress, the assigner owns the details.
        $repTask = $tasks->firstWhere('assignee_id', $rep->getKey());
        $this->assertInstanceOf(Task::class, $repTask);
        $this->assertTrue($repTask->isHandedOutTo($rep));
        $this->assertFalse($rep->can('update', $repTask));
        $this->assertTrue($rep->can('complete', $repTask));
        $this->assertTrue($routine->is($repTask->template()->first()));

        $stored = $this->stored($routine);
        $this->assertSame('2026-10-04', $stored->last_run_on?->toDateString());
        $this->assertSame('2026-10-11', $stored->next_run_on?->toDateString());
    }

    #[Test]
    public function each_assignee_is_told_by_bell_and_mail_like_any_assignment_and_the_assigner_is_not(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();

        $admin = $this->admin();
        $rep = $this->salesRep();
        $employee = $this->employee();
        $this->routine($admin, [], $rep, $employee, $admin);

        $this->assertSame(3, app(RoutineTaskService::class)->generateDue());

        foreach ([$rep, $employee] as $assignee) {
            Notification::assertSentTo(
                $assignee,
                RecordAssignedNotification::class,
                static fn (RecordAssignedNotification $notification, array $channels): bool => $channels === ['database', 'mail'],
            );
            Notification::assertSentToTimes($assignee, RecordAssignedNotification::class, 1);
        }

        // The assigner gets a task of their own, and no notice about it.
        $this->assertSame(1, Task::query()->where('assignee_id', $admin->getKey())->count());
        Notification::assertNotSentTo($admin, RecordAssignedNotification::class);
    }

    #[Test]
    public function completing_a_routine_task_tells_the_routine_assigner(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $rep = $this->salesRep();
        $this->routine($admin, [], $rep);
        app(RoutineTaskService::class)->generateDue();

        app(TaskService::class)->complete(Task::query()->sole(), $rep, 'Counted and posted.');

        Notification::assertSentTo($admin, TaskCompletedNotification::class);
        Notification::assertNotSentTo($rep, TaskCompletedNotification::class);
    }

    #[Test]
    public function a_routine_that_fails_is_reported_and_never_holds_up_the_others(): void
    {
        Exceptions::fake();

        $admin = $this->admin();
        $rep = $this->salesRep();
        $broken = $this->routine($admin, ['title' => 'Broken routine'], $rep);
        $this->routine($admin, ['title' => 'Healthy routine'], $rep);

        // The first routine of the pass cannot write its task.
        Task::creating(static function (Task $task): void {
            if ($task->title === 'Broken routine') {
                throw new RuntimeException('simulated write failure');
            }
        });

        $this->assertSame(1, app(RoutineTaskService::class)->generateDue());
        $this->assertSame(['Healthy routine'], Task::query()->pluck('title')->all());
        Exceptions::assertReported(RuntimeException::class);

        // Its day was rolled back with it: still due, so the next hour retries it.
        $stored = $this->stored($broken);
        $this->assertSame('2026-10-04', $stored->next_run_on?->toDateString());
        $this->assertNull($stored->last_run_on);
    }

    #[Test]
    public function the_creation_is_audited_with_the_assigner_as_the_causer(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $this->routine($admin, [], $rep);

        // The hourly run has no signed-in user.
        $this->assertFalse(auth()->check());
        app(RoutineTaskService::class)->generateDue();

        $task = Task::query()->sole();
        $log = DB::table('activity_log')
            ->where('subject_type', Task::class)->where('subject_id', $task->getKey())
            ->where('description', ActivityLogEvent::TaskCreated->value)->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->getKey(), (int) $log->causer_id);
    }

    #[Test]
    public function an_assignee_who_may_no_longer_sign_in_or_open_tasks_is_skipped(): void
    {
        $admin = $this->admin();
        $kept = $this->salesRep();
        $disabled = $this->salesRep();
        $demoted = $this->employee();
        $this->routine($admin, [], $kept, $disabled, $demoted);

        $disabled->forceFill(['status' => UserStatus::Disabled])->save();
        $demoted->syncRoles([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame(1, app(RoutineTaskService::class)->generateDue());
        $this->assertSame([$kept->getKey()], Task::query()->pluck('assignee_id')->map(static fn (mixed $id): int => (int) $id)->all());
    }

    #[Test]
    public function a_second_run_the_same_day_creates_nothing(): void
    {
        $admin = $this->admin();
        $this->routine($admin, [], $this->salesRep(), $this->employee());
        $service = app(RoutineTaskService::class);

        $this->assertSame(2, $service->generateDue());
        $this->assertSame(0, $service->generateDue());

        $this->travelToRiyadh('2026-10-04 23:30');
        $this->assertSame(0, $service->generateDue());
        $this->assertSame(2, Task::query()->count());

        // The next Sunday is a new day.
        $this->travelToRiyadh('2026-10-11 00:30');
        $this->assertSame(2, $service->generateDue());
        $this->assertSame(4, Task::query()->count());
    }

    #[Test]
    public function weeks_missed_while_the_server_was_down_produce_only_the_latest(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $routine = $this->routine($admin, ['due_time' => '17:00'], $rep);

        // The scheduler stopped before Sunday 4 October and is back on Tuesday 20 October.
        $this->travelToRiyadh('2026-10-20 10:00');

        $this->assertSame(1, app(RoutineTaskService::class)->generateDue());

        $task = Task::query()->sole();
        $this->assertTrue($task->due_at?->equalTo(CarbonImmutable::parse('2026-10-18 17:00', 'Asia/Riyadh')), 'only the latest missed Sunday is handed out');

        $stored = $this->stored($routine);
        $this->assertSame('2026-10-18', $stored->last_run_on?->toDateString());
        $this->assertSame('2026-10-25', $stored->next_run_on?->toDateString());
    }

    #[Test]
    public function a_paused_routine_and_a_routine_whose_day_has_not_come_create_nothing(): void
    {
        $admin = $this->admin();
        $paused = $this->routine($admin, [], $this->salesRep());
        app(RoutineTaskService::class)->pause($paused, $admin);
        $this->routine($admin, ['weekday' => 3], $this->salesRep());

        $this->assertSame(0, app(RoutineTaskService::class)->generateDue());
        $this->assertSame(0, Task::query()->count());
    }

    #[Test]
    public function the_day_turns_at_midnight_in_the_organisation_timezone(): void
    {
        $admin = $this->admin();
        app(SettingsRepository::class)->update([SettingsRepository::TIMEZONE => 'UTC'], $admin);

        $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00', 'UTC'));
        $routine = $this->routine($admin, ['weekday' => 0, 'due_time' => '17:00'], $this->salesRep());
        $this->assertSame('2026-10-04', $routine->next_run_on?->toDateString());

        // 22:30 UTC on Saturday is already Sunday in Riyadh, but not in the organisation's timezone.
        $this->travelTo(CarbonImmutable::parse('2026-10-03 22:30', 'UTC'));
        $this->assertSame(0, app(RoutineTaskService::class)->generateDue());

        $this->travelTo(CarbonImmutable::parse('2026-10-04 00:30', 'UTC'));
        $this->assertSame(1, app(RoutineTaskService::class)->generateDue());

        // Due at 17:00 organisation time, stored in the application timezone.
        $task = Task::query()->sole();
        $this->assertTrue($task->due_at?->equalTo(CarbonImmutable::parse('2026-10-04 17:00', 'UTC')));
        $this->assertSame('2026-10-04 20:00:00', DB::table('tasks')->where('id', $task->getKey())->value('due_at'));
    }

    #[Test]
    public function a_monthly_routine_on_day_28_runs_in_february(): void
    {
        $this->travelToRiyadh('2027-02-01 09:00');
        $admin = $this->admin();
        $this->routine($admin, ['frequency' => RoutineFrequency::Monthly->value, 'day_of_month' => 28, 'due_time' => '12:00'], $this->salesRep());

        $this->travelToRiyadh('2027-02-27 23:00');
        $this->assertSame(0, app(RoutineTaskService::class)->generateDue());

        $this->travelToRiyadh('2027-02-28 00:05');
        $this->assertSame(1, app(RoutineTaskService::class)->generateDue());
        $this->assertTrue(Task::query()->sole()->due_at?->equalTo(CarbonImmutable::parse('2027-02-28 12:00', 'Asia/Riyadh')));
        $this->assertSame('2027-03-28', TaskTemplate::query()->sole()->next_run_on?->toDateString());
    }

    #[Test]
    public function a_routine_whose_assigner_was_deleted_still_runs_in_their_name(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $rep = $this->salesRep();
        $this->routine($admin, [], $rep);

        $admin->delete();

        $this->assertSame(1, app(RoutineTaskService::class)->generateDue());

        $task = Task::query()->sole();
        $this->assertSame($admin->getKey(), $task->assigned_by, 'the assigner is kept as stored, never replaced (D-14)');
        Notification::assertSentTo($rep, RecordAssignedNotification::class);
    }

    #[Test]
    public function a_routine_with_no_assigner_left_still_hands_its_tasks_out_without_a_sender(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $rep = $this->salesRep();
        $routine = $this->routine($admin, [], $rep);

        // The panel only soft-deletes users; a row removed for good clears the stamp.
        $admin->forceDelete();
        $this->assertNull($this->stored($routine)->assigned_by);

        $this->assertSame(1, app(RoutineTaskService::class)->generateDue());

        $task = Task::query()->sole();
        $this->assertSame($rep->getKey(), (int) $task->assignee_id);
        $this->assertNull($task->assigned_by);
        $this->assertNull($task->created_by);
        Notification::assertNothingSent();
    }

    #[Test]
    public function deleting_a_routine_keeps_the_tasks_it_created(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $routine = $this->routine($admin, [], $rep);
        app(RoutineTaskService::class)->generateDue();

        app(RoutineTaskService::class)->delete($routine, $admin);

        $this->assertNull(TaskTemplate::query()->find($routine->getKey()));
        $this->assertSame(0, DB::table('task_template_user')->count());

        $task = Task::query()->sole();
        $this->assertNull($task->template_id);
        $this->assertSame($rep->getKey(), (int) $task->assignee_id);
        $this->assertSame($admin->getKey(), $task->assigned_by);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => TaskTemplate::class,
            'subject_id' => $routine->getKey(),
            'description' => ActivityLogEvent::LookupDeleted->value,
            'causer_id' => $admin->getKey(),
        ]);
    }

    #[Test]
    public function the_foreign_keys_keep_tasks_and_drop_assignee_rows(): void
    {
        $rules = DB::table('information_schema.REFERENTIAL_CONSTRAINTS as r')
            ->join('information_schema.KEY_COLUMN_USAGE as k', function ($join): void {
                $join->on('k.CONSTRAINT_NAME', '=', 'r.CONSTRAINT_NAME')->on('k.CONSTRAINT_SCHEMA', '=', 'r.CONSTRAINT_SCHEMA');
            })
            ->where('r.CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->whereIn('k.TABLE_NAME', ['tasks', 'task_templates', 'task_template_user'])
            ->whereIn('k.COLUMN_NAME', ['template_id', 'assigned_by', 'task_template_id', 'user_id'])
            ->get(['k.TABLE_NAME as table_name', 'k.COLUMN_NAME as column_name', 'r.DELETE_RULE as rule'])
            ->mapWithKeys(static fn (object $row): array => [$row->table_name.'.'.$row->column_name => $row->rule])
            ->all();

        $this->assertSame('SET NULL', $rules['tasks.template_id'] ?? null);
        $this->assertSame('SET NULL', $rules['task_templates.assigned_by'] ?? null);
        $this->assertSame('CASCADE', $rules['task_template_user.task_template_id'] ?? null);
        $this->assertSame('CASCADE', $rules['task_template_user.user_id'] ?? null);
    }

    #[Test]
    public function the_database_refuses_a_schedule_that_does_not_add_up(): void
    {
        $unrefused = [];

        // Each state breaks exactly one CHECK; the refusal must name it (MySQL
        // and MariaDB both put the constraint name in the message).
        foreach ([
            ['task_templates_schedule_check', ['weekday' => null]],
            ['task_templates_weekday_check', ['weekday' => 7]],
            ['task_templates_day_of_month_check', ['frequency' => RoutineFrequency::Monthly->value, 'weekday' => null, 'day_of_month' => 29]],
            ['task_templates_schedule_check', ['frequency' => RoutineFrequency::Monthly->value, 'weekday' => 1, 'day_of_month' => 5]],
            ['task_templates_due_in_days_check', ['due_in_days' => 31]],
            ['task_templates_next_run_on_check', ['is_active' => true, 'next_run_on' => null]],
        ] as [$constraint, $state]) {
            try {
                DB::transaction(static fn (): TaskTemplate => TaskTemplate::factory()->create($state));
                $unrefused[] = $constraint.' accepted '.json_encode($state);
            } catch (QueryException $exception) {
                if (! str_contains($exception->getMessage(), $constraint)) {
                    $unrefused[] = $constraint.' was not the refusal: '.$exception->getMessage();
                }
            }
        }

        $this->assertSame([], $unrefused);
        $this->assertSame(0, TaskTemplate::query()->count());
    }

    #[Test]
    public function the_command_reports_how_many_tasks_it_created(): void
    {
        $this->routine($this->admin(), [], $this->salesRep(), $this->employee());

        $this->artisan('tasks:generate-routines')
            ->expectsOutput('[tasks] 2 routine tasks created.')
            ->assertSuccessful();

        $this->artisan('tasks:generate-routines')
            ->expectsOutput('[tasks] 0 routine tasks created.')
            ->assertSuccessful();
    }

    #[Test]
    public function the_routine_pass_runs_hourly_on_one_server_without_overlapping(): void
    {
        $event = null;

        foreach (app(Schedule::class)->events() as $candidate) {
            if (! $candidate instanceof CallbackEvent && str_contains((string) $candidate->command, 'tasks:generate-routines')) {
                $event = $candidate;
            }
        }

        $this->assertInstanceOf(Event::class, $event, 'tasks:generate-routines is not scheduled');
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
    }
}
