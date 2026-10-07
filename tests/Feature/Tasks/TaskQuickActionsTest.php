<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\DueShortcut;
use App\Enums\Permission;
use App\Enums\RecurrenceFrequency;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskTransition;
use App\Enums\TaskUpdateKind;
use App\Exceptions\Access\UnassignableUserException;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\TaskActions;
use App\Livewire\TaskUndo;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Notifications\RecordAssignedNotification;
use App\Notifications\TaskCompletedNotification;
use App\Services\Tasks\TaskService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Callout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as Notifications;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Support\TaskActionsHost;
use Tests\TestCase;

/**
 * The quick controls of the Tasks workspace (decision D-27) — the status
 * moves and menus, the one-click complete with its «تراجع» undo, the
 * reschedule chips and quick add — held against every rule they must keep:
 * D-14 and its amendment (the handed-out assignee reports progress but
 * never cancels or moves the dates; only `task.assign` holders choose an
 * assignee, within their reach), D-20 (a mentioned participant reads and
 * comments, nothing more), D-25 (completing with open checklist items shows
 * the warning first). Four readers: the administrator who handed the task
 * out, the employee it was handed to, a colleague mentioned on it, and an
 * outsider who may not open it. Every write is proved to go through
 * TaskService by what only the service leaves behind: the ledger event,
 * the progress log and the notices.
 */
final class TaskQuickActionsTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private User $participant;

    private User $outsider;

    private TaskService $tasks;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        // Wednesday 2026-10-07, 10:00 in Riyadh (the organisation clock); the week starts on Sunday.
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00'));
        config()->set('mail.default', 'log');

        $this->admin = $this->admin();
        $this->employee = $this->employee();
        $this->participant = $this->employee();
        $this->outsider = $this->employee();
        $this->tasks = app(TaskService::class);

        $this->task = $this->tasks->create([
            'title' => 'Prepare the visit',
            'assignee_id' => $this->employee->getKey(),
            'due_at' => '2026-10-10 09:00:00',
        ], $this->admin);

        $this->tasks->comment($this->task, $this->admin, '@'.$this->participant->name.' please help', [(int) $this->participant->getKey()]);
    }

    #[Test]
    public function the_moves_offered_follow_the_policy_for_each_reader(): void
    {
        $this->assertTrue($this->task->isHandedOutTo($this->employee), 'precondition: the admin handed the task out');
        $this->assertTrue($this->participant->can('view', $this->task), 'precondition: the mention lets the colleague open the task');
        $this->assertFalse($this->outsider->can('view', $this->task), 'precondition: the outsider may not open it');

        $expected = [
            'admin' => [$this->admin, [TaskTransition::Start, TaskTransition::Complete, TaskTransition::Cancel]],
            'handed-out assignee' => [$this->employee, [TaskTransition::Start, TaskTransition::Complete]],
            'mentioned participant' => [$this->participant, []],
            'outsider' => [$this->outsider, []],
        ];

        foreach ($expected as $reader => [$user, $moves]) {
            $this->actingAs($user);

            $this->assertSame($moves, TaskActions::transitionsFor($this->task), "{$reader}: the moves offered");
            $this->assertSame(array_map(static fn (TaskTransition $move): string => 'status'.ucfirst($move->value), $moves), $this->menuEntries($this->task), "{$reader}: the status menu");

            $host = $this->host($user);

            foreach (['statusStart' => TaskTransition::Start, 'statusComplete' => TaskTransition::Complete, 'statusCancel' => TaskTransition::Cancel, 'statusReopen' => TaskTransition::Reopen] as $action => $move) {
                in_array($move, $moves, true)
                    ? $host->assertActionVisible($action)
                    : $host->assertActionHidden($action);
            }

            $moves === []
                ? $host->assertActionHidden('changeStatus')->assertActionHidden('quickComplete')
                : $host->assertActionVisible('changeStatus')->assertActionVisible('quickComplete');
        }
    }

    #[Test]
    public function the_handed_out_assignee_starts_and_completes_in_one_click_through_the_service(): void
    {
        Notifications::fake();

        $this->host($this->employee)->callAction('statusStart')->assertNotified(__('tasks.notifications.started'));

        $this->assertSame(TaskStatus::InProgress, $this->task->refresh()->status);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskStarted->value, 'subject_id' => $this->task->getKey(), 'causer_id' => $this->employee->getKey()]);
        $this->assertTrue(TaskUpdate::query()->where('task_id', $this->task->getKey())->where('kind', TaskUpdateKind::Progress->value)->where('status', TaskStatus::InProgress->value)->exists(), 'the start is in the progress log');

        // No checklist: no dialog at all — mounting completes.
        $this->host($this->employee)->mountAction('quickComplete');

        $this->assertSame(TaskStatus::Completed, $this->task->refresh()->status);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskCompleted->value, 'subject_id' => $this->task->getKey(), 'causer_id' => $this->employee->getKey()]);
        Notifications::assertSentTo($this->admin, TaskCompletedNotification::class);
    }

    #[Test]
    public function the_completion_toast_offers_an_undo_that_reopens_through_the_reopen_rule(): void
    {
        $this->host($this->employee)->callAction('quickComplete');

        $undo = $this->undoInToast();
        $this->assertSame(['task' => (int) $this->task->getKey()], $undo, 'the toast carries «تراجع» for this task');
        $this->assertSame(TaskStatus::Completed, $this->task->refresh()->status);

        Livewire::actingAs($this->employee)
            ->test(TaskUndo::class)
            ->dispatch(TaskActions::UNDO_COMPLETE_EVENT, task: $undo['task'])
            ->assertDispatched(TaskActions::CHANGED_EVENT)
            ->assertNotified(__('tasks.notifications.reopened'));

        $this->assertSame(TaskStatus::Pending, $this->task->refresh()->status);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskReopened->value, 'subject_id' => $this->task->getKey(), 'causer_id' => $this->employee->getKey()]);
    }

    #[Test]
    public function undoing_the_completion_of_a_repeating_task_withdraws_the_occurrence_it_scheduled(): void
    {
        $weekly = $this->tasks->create([
            'title' => 'Weekly stock count',
            'assignee_id' => $this->employee->getKey(),
            'due_at' => '2026-10-10 09:00:00',
            'recurrence_frequency' => RecurrenceFrequency::Weekly->value,
            'recurrence_interval' => 1,
        ], $this->admin);
        $series = static fn (): array => Task::query()->where('series_id', $weekly->getKey())->orderBy('id')->get()
            ->map(static fn (Task $task): string => $task->status->value.' '.$task->due_at?->format('Y-m-d H:i'))->all();

        $this->host($this->employee, $weekly)->callAction('quickComplete');
        $this->assertSame(['pending 2026-10-17 09:00'], $series(), 'the completion scheduled the next occurrence');
        $scheduled = Task::query()->where('series_id', $weekly->getKey())->sole();

        Livewire::actingAs($this->employee)
            ->test(TaskUndo::class)
            ->dispatch(TaskActions::UNDO_COMPLETE_EVENT, task: (int) $weekly->getKey())
            ->assertNotified(__('tasks.notifications.reopened'));

        $this->assertSame(TaskStatus::Pending, $weekly->refresh()->status);
        $this->assertSame([], $series(), 'the undo withdrew the occurrence the completion scheduled');
        $this->assertSoftDeleted($scheduled);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskDeleted->value, 'subject_id' => $scheduled->getKey(), 'causer_id' => $this->employee->getKey()]);
        $this->assertSame((int) $scheduled->getKey(), Activity::query()->where('description', ActivityLogEvent::TaskReopened->value)->where('subject_id', $weekly->getKey())->sole()->properties->get('withdrawn_occurrence_id'));

        // Completing again schedules exactly one next occurrence.
        $this->host($this->employee, $weekly->refresh())->callAction('quickComplete');
        $this->assertSame(['pending 2026-10-17 09:00'], $series());

        // A plain reopen keeps the occurrence, and completing again never adds a second copy.
        $this->tasks->reopen($weekly->refresh(), $this->admin);
        $this->tasks->complete($weekly->refresh(), $this->employee);
        $this->assertSame(['pending 2026-10-17 09:00'], $series(), 'one occurrence per due date, however often the task is completed');
    }

    #[Test]
    public function the_undo_keeps_a_scheduled_occurrence_someone_already_worked_on(): void
    {
        $weekly = $this->tasks->create([
            'title' => 'Weekly report',
            'assignee_id' => $this->employee->getKey(),
            'due_at' => '2026-10-10 09:00:00',
            'recurrence_frequency' => RecurrenceFrequency::Weekly->value,
            'recurrence_interval' => 1,
        ], $this->admin);

        $this->tasks->complete($weekly, $this->employee);
        $next = Task::query()->where('series_id', $weekly->getKey())->sole();
        $this->tasks->comment($next, $this->admin, 'Please add the new branch this week', []);

        $this->tasks->undoCompletion($weekly->refresh(), $this->employee);

        $this->assertSame(TaskStatus::Pending, $weekly->refresh()->status);
        $this->assertNotSoftDeleted($next);
        $this->assertNull(Activity::query()->where('description', ActivityLogEvent::TaskReopened->value)->where('subject_id', $weekly->getKey())->sole()->properties->get('withdrawn_occurrence_id'));
    }

    #[Test]
    public function the_undo_changes_nothing_once_the_task_is_no_longer_completed(): void
    {
        $this->host($this->employee)->callAction('statusComplete');
        $undo = $this->undoInToast();
        $this->assertNotNull($undo);

        // The admin reopened and cancelled it meanwhile: the late undo must not reopen a cancelled task.
        $this->tasks->reopen($this->task, $this->admin);
        $this->tasks->cancel($this->task->refresh(), $this->admin);

        Livewire::actingAs($this->admin)
            ->test(TaskUndo::class)
            ->dispatch(TaskActions::UNDO_COMPLETE_EVENT, task: $undo['task'])
            ->assertNotified(__('tasks.validation.undo_not_completed'))
            ->assertNotDispatched(TaskActions::CHANGED_EVENT);

        $this->assertSame(TaskStatus::Cancelled, $this->task->refresh()->status);
    }

    #[Test]
    public function the_undo_is_refused_with_a_toast_to_a_participant_an_outsider_and_after_a_delete(): void
    {
        $this->tasks->complete($this->task, $this->employee);

        // Refused, unreadable and garbage ids all get the same danger toast — never an error page,
        // and nothing that tells an outsider the task exists.
        foreach ([[$this->participant, (int) $this->task->getKey()], [$this->outsider, (int) $this->task->getKey()], [$this->employee, 'not-an-id']] as [$reader, $id]) {
            Livewire::actingAs($reader)
                ->test(TaskUndo::class)
                ->dispatch(TaskActions::UNDO_COMPLETE_EVENT, task: $id)
                ->assertOk()
                ->assertNotified(__('tasks.validation.undo_unavailable'))
                ->assertNotDispatched(TaskActions::CHANGED_EVENT);
        }

        $this->assertSame(TaskStatus::Completed, $this->task->refresh()->status);

        // Deleted while the toast was showing: the assignee's late undo gets the toast too.
        $this->task->delete();

        Livewire::actingAs($this->employee)
            ->test(TaskUndo::class)
            ->dispatch(TaskActions::UNDO_COMPLETE_EVENT, task: (int) $this->task->getKey())
            ->assertOk()
            ->assertNotified(__('tasks.validation.undo_unavailable'));

        $this->assertSame(TaskStatus::Completed, $this->task->refresh()->status);
    }

    #[Test]
    public function the_toast_offers_the_undo_to_whoever_completed_the_task(): void
    {
        // Completing and reopening a completed task are both `progress` (D-14 amendment), so whoever
        // could complete it is offered the undo: the assigner here, and an employee on their own to-do.
        $this->host($this->admin)->callAction('quickComplete');
        $this->assertSame(['task' => (int) $this->task->getKey()], $this->undoInToast());

        $own = $this->tasks->create(['title' => 'Own to-do', 'assignee_id' => $this->participant->getKey()], $this->participant);
        $this->host($this->participant, $own)->callAction('quickComplete');
        $this->assertSame(['task' => (int) $own->getKey()], $this->undoInToast());
    }

    #[Test]
    public function the_assignee_may_not_cancel_reschedule_or_reopen_a_cancelled_task_and_the_admin_may(): void
    {
        $due = $this->task->due_at?->toIso8601String();

        $this->host($this->employee)
            ->assertActionHidden('statusCancel')
            ->assertActionHidden('rescheduleToday')
            ->assertActionHidden('rescheduleTomorrow')
            ->assertActionHidden('rescheduleNextWeek')
            ->assertActionHidden('reschedulePick')
            ->assertActionHidden('reschedule')
            // Mounting a refused action by name, as a forged request would, runs nothing.
            ->call('mountAction', 'statusCancel')
            ->call('mountAction', 'rescheduleTomorrow');

        $this->task->refresh();
        $this->assertSame(TaskStatus::Pending, $this->task->status, 'a refused cancel changes nothing');
        $this->assertSame($due, $this->task->due_at?->toIso8601String(), 'a refused reschedule changes nothing');

        $this->host($this->admin)->callAction('statusCancel')->assertNotified(__('tasks.notifications.cancelled'));
        $this->assertSame(TaskStatus::Cancelled, $this->task->refresh()->status);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskCancelled->value, 'subject_id' => $this->task->getKey(), 'causer_id' => $this->admin->getKey()]);

        $this->host($this->employee)->assertActionHidden('statusReopen');
        $this->host($this->admin)->assertActionVisible('statusReopen')->callAction('statusReopen');
        $this->assertSame(TaskStatus::Pending, $this->task->refresh()->status);
    }

    #[Test]
    public function a_mentioned_participant_and_an_outsider_get_no_quick_control(): void
    {
        foreach ([$this->participant, $this->outsider] as $reader) {
            $this->host($reader)
                ->assertActionHidden('statusStart')
                ->assertActionHidden('quickComplete')
                ->assertActionHidden('changeStatus')
                ->assertActionHidden('rescheduleTomorrow')
                ->assertActionHidden('reschedule')
                ->call('mountAction', 'statusStart')
                ->call('mountAction', 'quickComplete')
                ->call('mountAction', 'rescheduleTomorrow');
        }

        $this->assertSame(TaskStatus::Pending, $this->task->refresh()->status);
        $this->assertSame('2026-10-10 09:00', $this->task->due_at?->format('Y-m-d H:i'));
    }

    #[Test]
    public function completing_with_open_checklist_items_shows_the_d25_warning_first(): void
    {
        $task = $this->tasks->create([
            'title' => 'Multi-step job',
            'assignee_id' => $this->employee->getKey(),
            'checklist' => [['body' => 'One'], ['body' => 'Two'], ['body' => 'Three']],
        ], $this->admin);
        $first = TaskChecklistItem::query()->where('task_id', $task->getKey())->orderBy('position')->firstOrFail();
        $this->tasks->tickChecklist($task, $this->employee, [(int) $first->getKey()]);

        app()->setLocale('en');

        $this->host($this->employee, $task)
            ->mountAction('quickComplete')
            ->assertSchemaComponentExists('checklistWarning', checkComponentUsing: self::warning('Not done: 2 of 3 checklist items.'));
        $this->assertSame(TaskStatus::Pending, $task->refresh()->status, 'the warning comes before the completion');

        $this->host($this->employee, $task)
            ->mountAction([TestAction::make('changeStatus'), TestAction::make('statusComplete')])
            ->assertSchemaComponentExists('checklistWarning', checkComponentUsing: self::warning('Not done: 2 of 3 checklist items.'));

        app()->setLocale('ar');

        $this->host($this->employee, $task)
            ->mountAction('statusComplete')
            ->assertSchemaComponentExists('checklistWarning', checkComponentUsing: self::warning('لم يُنجز 2 من 3 بنود في قائمة التحقق.'))
            ->callMountedAction();

        $this->assertSame(TaskStatus::Completed, $task->refresh()->status, 'completing with open items stays allowed');
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskCompleted->value, 'subject_id' => $task->getKey()]);
        $this->assertStringContainsString('"checklist_open":2', (string) json_encode(
            Activity::query()->where('description', ActivityLogEvent::TaskCompleted->value)->where('subject_id', $task->getKey())->value('properties'),
        ));
    }

    #[Test]
    public function the_change_status_dialog_offers_the_moves_as_buttons_that_each_keep_their_rule(): void
    {
        $this->host($this->employee)
            ->assertActionVisible([TestAction::make('changeStatus'), TestAction::make('statusStart')])
            ->assertActionVisible([TestAction::make('changeStatus'), TestAction::make('statusComplete')])
            ->assertActionHidden([TestAction::make('changeStatus'), TestAction::make('statusCancel')])
            ->callAction([TestAction::make('changeStatus'), TestAction::make('statusStart')]);

        $this->assertSame(TaskStatus::InProgress, $this->task->refresh()->status);
    }

    #[Test]
    public function the_reschedule_chips_move_an_open_task_on_the_organisation_calendar_keeping_its_time(): void
    {
        $host = $this->host($this->admin);

        // Due Saturday 09:00; it is Wednesday 10:00, so "today" at 09:00 is past: 17:00 today.
        $host->callAction('rescheduleToday')->assertNotified(__('tasks.notifications.rescheduled', ['due' => '2026-10-07 17:00']));
        $this->assertSame('2026-10-07 17:00', $this->dueOf($this->task));

        $host->callAction('rescheduleTomorrow');
        $this->assertSame('2026-10-08 17:00', $this->dueOf($this->task), 'tomorrow keeps the time of day the task now has');

        $host->callAction('rescheduleNextWeek');
        $this->assertSame('2026-10-11 17:00', $this->dueOf($this->task), 'next week: the first day of the next organisation week (Sunday)');

        $host->callAction('reschedulePick', data: ['due_on' => '2026-10-20']);
        $this->assertSame('2026-10-20 17:00', $this->dueOf($this->task));

        $host->callAction('reschedule', data: ['due_shortcut' => DueShortcut::Pick->value, 'due_on' => '2026-10-22']);
        $this->assertSame('2026-10-22 17:00', $this->dueOf($this->task));

        $host->callAction('reschedule', data: ['due_shortcut' => DueShortcut::Pick->value, 'due_on' => null])
            ->assertHasActionErrors(['due_on' => 'required']);

        $this->assertDatabaseHas('activity_log', ['subject_id' => $this->task->getKey(), 'causer_id' => $this->admin->getKey(), 'event' => 'updated']);
    }

    #[Test]
    public function a_moved_meeting_carries_its_span_and_a_closed_task_keeps_its_date(): void
    {
        $meeting = $this->tasks->create([
            'title' => 'Site meeting',
            'kind' => TaskKind::Meeting->value,
            'assignee_id' => $this->admin->getKey(),
            'due_at' => '2026-10-09 11:00:00',
            'starts_at' => '2026-10-09 11:00:00',
            'ends_at' => '2026-10-09 12:30:00',
        ], $this->admin);

        $this->host($this->admin, $meeting)->callAction('rescheduleNextWeek');
        $meeting->refresh();

        $this->assertSame('2026-10-11 11:00', $meeting->due_at?->format('Y-m-d H:i'));
        $this->assertSame('2026-10-11 11:00', $meeting->starts_at?->format('Y-m-d H:i'));
        $this->assertSame('2026-10-11 12:30', $meeting->ends_at?->format('Y-m-d H:i'), 'the meeting keeps its length');

        $this->tasks->complete($meeting, $this->admin);
        $this->host($this->admin, $meeting)->assertActionHidden('rescheduleTomorrow');

        $this->expectExceptionMessage(__('tasks.validation.reschedule_not_open'));
        $this->tasks->moveDue($meeting->refresh(), Carbon::parse('2026-10-30 09:00:00'), $this->admin);
    }

    #[Test]
    public function quick_add_gives_an_employee_a_personal_to_do_whatever_assignee_they_send(): void
    {
        $this->host($this->employee)
            ->mountAction('quickAdd')
            ->assertFormFieldHidden('assignee_id');

        $this->host($this->employee)->callAction('quickAdd', data: [
            'title' => 'Call the supplier',
            'assignee_id' => $this->admin->getKey(),
            'due_shortcut' => DueShortcut::Tomorrow->value,
            'priority' => TaskPriority::High->value,
        ])->assertHasNoErrors()->assertNotified(__('tasks.notifications.added'));

        $task = Task::query()->where('title', 'Call the supplier')->firstOrFail();

        $this->assertSame((int) $this->employee->getKey(), (int) $task->assignee_id, 'a forged assignee is ignored without task.assign');
        $this->assertSame((int) $this->employee->getKey(), (int) $task->assigned_by);
        $this->assertSame((int) $this->employee->getKey(), (int) $task->created_by);
        $this->assertSame(TaskPriority::High, $task->priority);
        $this->assertSame(TaskKind::Task, $task->kind);
        $this->assertSame('2026-10-08 17:00', $this->dueOf($task));
        $this->assertDatabaseHas('activity_log', ['subject_id' => $task->getKey(), 'event' => 'created', 'causer_id' => $this->employee->getKey()]);
    }

    #[Test]
    public function quick_add_lets_a_task_assign_holder_hand_work_out_within_reach_only(): void
    {
        Notifications::fake();

        $this->host($this->admin)->callAction('quickAdd', data: [
            'title' => 'Count the stock',
            'assignee_id' => $this->employee->getKey(),
            'due_shortcut' => DueShortcut::None->value,
            'priority' => TaskPriority::Urgent->value,
        ])->assertHasNoErrors();

        $handed = Task::query()->where('title', 'Count the stock')->firstOrFail();
        $this->assertSame((int) $this->employee->getKey(), (int) $handed->assignee_id);
        $this->assertSame((int) $this->admin->getKey(), (int) $handed->assigned_by);
        $this->assertNull($handed->due_at, '"no date" leaves the task without one');
        $this->assertTrue($handed->isHandedOutTo($this->employee));
        Notifications::assertSentTo($this->employee, RecordAssignedNotification::class);

        // A holder of task.assign whose reach is their own tasks only: the dialog offers only themselves,
        // a forged choice fails its validation, and the service behind it refuses the same choice.
        $narrow = $this->userWithPermissions(null, [Permission::TaskViewAny, Permission::TaskCreate, Permission::TaskUpdate, Permission::TaskAssign]);

        $this->host($narrow)
            ->mountAction('quickAdd')
            ->assertFormFieldVisible('assignee_id')
            ->fillForm([
                'title' => 'Out of reach',
                'assignee_id' => $this->employee->getKey(),
                'due_shortcut' => DueShortcut::Today->value,
                'priority' => TaskPriority::Medium->value,
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['assignee_id']);

        $this->assertFalse(Task::query()->where('title', 'Out of reach')->exists(), 'nothing is created outside the reach');

        $this->expectException(UnassignableUserException::class);
        $this->tasks->create(['title' => 'Out of reach', 'assignee_id' => $this->employee->getKey()], $narrow);
    }

    #[Test]
    public function quick_add_lists_each_assignee_under_their_own_id_so_the_name_picked_is_the_person_assigned(): void
    {
        Notifications::fake();

        // Distinct names, so a label points at exactly one person.
        foreach (['Noor Employee' => $this->employee, 'Sami Participant' => $this->participant, 'Huda Outsider' => $this->outsider] as $name => $user) {
            $user->forceFill(['name' => $name])->save();
        }

        $options = [];

        $this->host($this->admin)
            ->mountAction('quickAdd')
            ->assertFormFieldExists('assignee_id', static function (Select $field) use (&$options): bool {
                $options = $field->getOptions();

                return true;
            });

        $this->assertSame((int) $this->admin->getKey(), array_key_first($options), 'the reader comes first');
        $this->assertCount(count(array_unique(array_keys($options))), $options);
        $this->assertSame(1, count(array_keys($options, $this->admin->name, true)), 'the reader is listed once');

        $names = User::query()->whereKey(array_keys($options))->pluck('name', 'id')->all();

        foreach ($options as $id => $label) {
            $this->assertSame($names[$id] ?? null, $label, "option {$id} is labelled with that user's name");
        }

        // Pick the employee by the key their name is listed under: the task goes to the employee.
        $picked = array_search($this->employee->name, $options, true);
        $this->assertSame((int) $this->employee->getKey(), $picked);

        $this->host($this->admin)->callAction('quickAdd', data: [
            'title' => 'Picked by name',
            'assignee_id' => $picked,
            'due_shortcut' => DueShortcut::None->value,
            'priority' => TaskPriority::Medium->value,
        ])->assertHasNoErrors();

        $this->assertSame((int) $this->employee->getKey(), (int) Task::query()->where('title', 'Picked by name')->value('assignee_id'));
        Notifications::assertSentTo($this->employee, RecordAssignedNotification::class);
    }

    #[Test]
    public function quick_add_is_offered_only_to_those_who_may_create_tasks(): void
    {
        $this->host($this->admin)->assertActionVisible('quickAdd');
        $this->host($this->employee)->assertActionVisible('quickAdd');
        $this->host($this->readOnly())->assertActionHidden('quickAdd');
    }

    #[Test]
    public function more_options_opens_the_full_form_prefilled_and_the_form_trusts_nothing_in_the_address(): void
    {
        $host = $this->host($this->admin)
            ->callAction(TestAction::make('quickAdd')->arguments(['more' => true]), data: [
                'title' => 'Plan the launch',
                'assignee_id' => $this->employee->getKey(),
                'due_shortcut' => DueShortcut::Tomorrow->value,
                'priority' => TaskPriority::High->value,
            ]);

        // The typed values wait in the server session; the address carries a one-time token only.
        $stash = session(TaskActions::PREFILL_SESSION_KEY);
        $this->assertIsArray($stash);
        $this->assertSame([
            'title' => 'Plan the launch',
            'priority' => TaskPriority::High->value,
            'due' => '2026-10-08 17:00',
            'assignee' => (string) $this->employee->getKey(),
        ], $stash['values']);
        $token = $stash['token'];
        $this->assertIsString($token);
        $host->assertRedirect(TaskResource::getUrl('create', [TaskActions::PREFILL_PARAMETER => $token]));

        $this->assertFalse(Task::query()->where('title', 'Plan the launch')->exists(), '«خيارات أكثر» creates nothing by itself');

        Livewire::withQueryParams([TaskActions::PREFILL_PARAMETER => $token])
            ->actingAs($this->admin)
            ->test(CreateTask::class)
            ->assertSchemaStateSet([
                'title' => 'Plan the launch',
                'priority' => TaskPriority::High,
                'due_at' => '2026-10-08 17:00',
                'owner_id' => (int) $this->employee->getKey(),
            ]);

        $this->assertNull(session(TaskActions::PREFILL_SESSION_KEY), 'the prefill is taken once');
        $this->assertSame([], TaskActions::takePrefill($token, $this->admin), 'a used token prefills nothing');

        // The old address shape (values in the query string) is no longer read.
        Livewire::withQueryParams(['title' => 'From the address', 'priority' => 'high'])
            ->actingAs($this->admin)
            ->test(CreateTask::class)
            ->assertSchemaStateSet(['title' => null]);

        // A forged token prefills nothing and spends the waiting slot.
        session()->put(TaskActions::PREFILL_SESSION_KEY, ['token' => 'real-token', 'values' => ['title' => 'Waiting']]);
        $this->assertSame([], TaskActions::takePrefill('forged-token', $this->admin));
        $this->assertSame([], TaskActions::takePrefill('real-token', $this->admin));

        // The employee may not choose an assignee, so an assignee in the address is dropped; the rest
        // is validated value by value.
        $this->assertSame(
            ['title' => 'Plan the launch'],
            TaskActions::createPrefill([
                'title' => 'Plan the launch',
                'priority' => 'critical',
                'due' => '2026-02-31 17:00',
                'assignee' => (string) $this->admin->getKey(),
            ], $this->employee),
        );

        $this->assertSame([], TaskActions::createPrefill([
            'title' => str_repeat('x', 201),
            'due' => '17:00 2026-10-08',
            'assignee' => '1 OR 1=1',
            'priority' => ['high'],
        ], $this->admin));
    }

    #[Test]
    public function the_quick_controls_write_only_through_task_service(): void
    {
        // Structural guard: TaskActions presents and authorises; every write is TaskService's (CLAUDE.md §2).
        // The code alone: the docblocks name TaskService::create() on purpose.
        $source = '';

        foreach (token_get_all((string) file_get_contents(app_path('Filament/Support/TaskActions.php'))) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $source .= is_array($token) ? $token[1] : $token;
        }

        foreach (['->save(', '->update(', '::create(', '->forceFill(', '->delete(', 'DB::'] as $write) {
            $this->assertStringNotContainsString($write, $source, "TaskActions writes past TaskService with {$write}");
        }
    }

    /** A host page over the task (default: the handed-out one), as the given reader. */
    private function host(User $user, ?Task $task = null): Testable
    {
        $this->actingAs($user);

        return Livewire::actingAs($user)->test(TaskActionsHost::class, ['taskId' => (int) ($task ?? $this->task)->getKey()]);
    }

    /**
     * The names of the status menu's entries the signed-in reader is offered.
     *
     * @return list<string>
     */
    private function menuEntries(Task $task): array
    {
        $names = [];

        foreach (TaskActions::statusMenu()->record($task)->getActions() as $action) {
            if ($action instanceof Action && $action->isVisible()) {
                $names[] = $action->getName();
            }
        }

        return $names;
    }

    /**
     * The event data of the «تراجع» button on the last completion toast
     * sent, or null when it carries none. Filament moves a sent toast from
     * `filament.notifications` to `filament.claimed_notifications` once the
     * page has picked it up; both are read.
     *
     * @return array<string, mixed>|null
     */
    private function undoInToast(): ?array
    {
        $toasts = [
            ...(array) session('filament.claimed_notifications', []),
            ...(array) session('filament.notifications', []),
        ];
        $completed = array_values(array_filter($toasts, static fn (mixed $toast): bool => is_array($toast) && ($toast['title'] ?? null) === __('tasks.notifications.completed')));
        $last = end($completed);

        if (! is_array($last)) {
            return null;
        }

        foreach ($last['actions'] ?? [] as $action) {
            if (($action['name'] ?? null) === 'undo' && ($action['event'] ?? null) === TaskActions::UNDO_COMPLETE_EVENT) {
                return $action['eventData'] ?? [];
            }
        }

        return null;
    }

    /** The task's due moment on the organisation clock (Riyadh here). */
    private function dueOf(Task $task): ?string
    {
        return $task->refresh()->due_at?->format('Y-m-d H:i');
    }

    private static function warning(string $text): Closure
    {
        return static fn (Callout $callout): bool => $callout->isVisible() && (string) $callout->getHeading() === $text;
    }
}
