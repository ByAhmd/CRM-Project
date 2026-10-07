<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\TaskStatus;
use App\Filament\Pages\TasksBoard;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\TaskActions;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskBoardFeed;
use App\Services\Tasks\TaskService;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Feature\QualityPass\Performance\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * The tasks board's two views and «مهامي فقط» (decisions D-14, D-27).
 *
 * The by-status view shows the tasks the reader may open in Pending · In
 * progress · Completed this week; dragging a card asks moveTask() for the
 * transition, which the server re-checks against everything a forged or
 * stale drop could get wrong — an id that does not exist, a task outside
 * the reader's reach, a column off the board, a status that changed
 * meanwhile, a move the workflow has no road for, a move the policy
 * refuses — before mounting the same TaskActions action the status pill
 * runs. A drop on Completed with open checklist items opens the D-25
 * warning. The by-assignee view (D-14) keeps showing everyone's open work.
 */
final class TasksBoardStatusViewTest extends TestCase
{
    use CountsQueries;
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
        // Wednesday 2026-10-07, 10:00 in Riyadh; the organisation week began on Sunday 2026-10-04.
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
    public function the_by_status_view_shows_the_readable_tasks_in_three_columns_and_hides_cancelled_and_older_completions(): void
    {
        $this->taskAssignedTo($this->employee, TaskStatus::InProgress, ['title' => 'Started work']);
        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['title' => 'Done on Monday', 'completed_at' => '2026-10-05 12:00:00']);
        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['title' => 'Done last week', 'completed_at' => '2026-10-01 12:00:00']);
        $this->taskAssignedTo($this->employee, TaskStatus::Cancelled, ['title' => 'Called off']);
        $this->taskAssignedTo($this->outsider, TaskStatus::Pending, ['title' => 'Someone else entirely']);

        $board = $this->statusBoard($this->employee)
            ->assertSee('Prepare the visit')
            ->assertSee('Started work')
            ->assertSee('Done on Monday')
            ->assertDontSee('Done last week')
            ->assertDontSee('Called off')
            ->assertDontSee('Someone else entirely')
            ->assertSee(__('tasks.board_ux.columns.this_week'));

        $html = $board->html();
        $this->assertLessThan(strpos($html, 'data-status="in_progress"'), strpos($html, 'data-status="pending"'), 'Pending comes first');
        $this->assertLessThan(strpos($html, 'data-status="completed"'), strpos($html, 'data-status="in_progress"'), 'then In progress, then Completed');
        $this->assertStringNotContainsString('data-status="cancelled"', $html);

        // The by-assignee view (D-14) still shows everyone's open work, the outsider's included.
        $board->set('boardView', TasksBoard::VIEW_ASSIGNEE)
            ->assertSee('Someone else entirely')
            ->assertSee(__('tasks.pages.board.stats.open'));
    }

    #[Test]
    public function a_card_offers_drag_and_the_status_menu_only_for_the_moves_the_reader_may_make(): void
    {
        $id = (int) $this->task->getKey();
        $start = "mountAction('statusStart', { task: {$id} })";
        $complete = "mountAction('statusComplete', { task: {$id} })";
        $cancel = "mountAction('statusCancel', { task: {$id} })";

        // The assigner: start, complete and cancel; the card drags.
        $html = $this->statusBoard($this->admin)->html();
        foreach ([$start, $complete, $cancel] as $click) {
            $this->assertStringContainsString($click, $html);
        }
        $this->assertStringContainsString('x-sortable-item="'.$id.'"', $html);

        // The handed-out assignee: start and complete, never cancel (D-14 amendment).
        $html = $this->statusBoard($this->employee)->html();
        $this->assertStringContainsString($start, $html);
        $this->assertStringContainsString($complete, $html);
        $this->assertStringNotContainsString($cancel, $html);
        $this->assertStringContainsString('x-sortable-item="'.$id.'"', $html);

        // The mentioned participant sees the card, a plain pill, nothing to drag (D-20).
        $html = $this->statusBoard($this->participant)->assertSee('Prepare the visit')->html();
        $this->assertStringNotContainsString($start, $html);
        $this->assertStringNotContainsString('x-sortable-item="'.$id.'"', $html);

        // The outsider: no card on the by-status view; on the by-assignee view the card has no link and no menu.
        $this->statusBoard($this->outsider)->assertDontSee('Prepare the visit');
        $html = Livewire::actingAs($this->outsider)->test(TasksBoard::class)->set('boardView', TasksBoard::VIEW_ASSIGNEE)->assertSee('Prepare the visit')->html();
        $this->assertStringNotContainsString($start, $html);
        $this->assertStringNotContainsString('href="'.TaskResource::getUrl('view', ['record' => $this->task]).'"', $html);
    }

    #[Test]
    public function dragging_to_in_progress_starts_the_task_through_the_service(): void
    {
        $board = $this->statusBoard($this->employee);
        $revision = $board->get('revision');

        $board->call('moveTask', $this->task->getKey(), 'pending', 'in_progress')
            ->assertNotified(__('tasks.notifications.started'));

        $this->assertSame(TaskStatus::InProgress, $this->task->refresh()->status);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskStarted->value, 'subject_id' => $this->task->getKey(), 'causer_id' => $this->employee->getKey()]);
        $this->assertGreaterThan($revision, $board->get('revision'), 'the columns re-render after a drop');
    }

    #[Test]
    public function dragging_to_completed_completes_at_once_or_shows_the_checklist_warning_first(): void
    {
        $this->statusBoard($this->employee)
            ->call('moveTask', $this->task->getKey(), 'pending', 'completed')
            ->assertNotified(__('tasks.notifications.completed'));

        $this->assertSame(TaskStatus::Completed, $this->task->refresh()->status);

        $listed = $this->tasks->create(['title' => 'Pack the samples', 'assignee_id' => $this->employee->getKey()], $this->admin);
        $this->checklistItemOn($listed, 'Box one');
        $this->checklistItemOn($listed, 'Box two');

        $board = $this->statusBoard($this->employee)
            ->call('moveTask', $listed->getKey(), 'pending', 'completed')
            ->assertActionMounted('statusComplete')
            ->assertMountedActionModalSee(trans_choice('tasks.confirmations.checklist_open', 2, ['count' => 2, 'total' => 2]));

        $this->assertSame(TaskStatus::Pending, $listed->refresh()->status, 'nothing completes before the warning is confirmed');

        $board->callMountedAction()->assertHasNoActionErrors();

        $this->assertSame(TaskStatus::Completed, $listed->refresh()->status);
    }

    #[Test]
    public function dragging_a_completed_task_back_to_pending_reopens_it(): void
    {
        $this->tasks->complete($this->task, $this->employee);

        $this->statusBoard($this->employee)
            ->call('moveTask', $this->task->getKey(), 'completed', 'pending')
            ->assertNotified(__('tasks.notifications.reopened'));

        $this->assertSame(TaskStatus::Pending, $this->task->refresh()->status);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskReopened->value, 'subject_id' => $this->task->getKey()]);
    }

    #[Test]
    public function forged_stale_and_refused_drops_change_nothing_and_say_why(): void
    {
        $hidden = $this->taskAssignedTo($this->admin, TaskStatus::Pending, ['title' => 'Admin private task']);
        $this->assertFalse($this->employee->can('view', $hidden), 'precondition: the employee may not open the admin task');

        $cases = [
            'a forged id' => [$this->employee, 999999, 'pending', 'in_progress', __('tasks.board_ux.notifications.not_found')],
            'garbage id' => [$this->employee, 'abc', 'pending', 'in_progress', __('tasks.board_ux.notifications.not_found')],
            'a task outside reach' => [$this->employee, $hidden->getKey(), 'pending', 'in_progress', __('tasks.board_ux.notifications.not_found')],
            'a column off the board' => [$this->admin, $this->task->getKey(), 'pending', 'cancelled', __('tasks.board_ux.notifications.not_a_column')],
            'an unknown column' => [$this->admin, $this->task->getKey(), 'pending', 'archived', __('tasks.board_ux.notifications.not_a_column')],
            'a stale origin' => [$this->employee, $this->task->getKey(), 'in_progress', 'completed', __('tasks.board_ux.notifications.stale', ['status' => TaskStatus::Pending->getLabel()])],
            'a move the policy refuses' => [$this->participant, $this->task->getKey(), 'pending', 'in_progress', __('tasks.board_ux.notifications.not_allowed', ['move' => __('enums.task_transition.start')])],
        ];

        foreach ($cases as $case => [$user, $taskId, $from, $to, $reason]) {
            $board = $this->statusBoard($user)->call('moveTask', $taskId, $from, $to);

            $this->assertSame($reason, $this->refusalBody(), "{$case}: the message");
            $board->assertActionNotMounted('statusStart')->assertActionNotMounted('statusComplete');

            $this->assertSame(TaskStatus::Pending, $this->task->refresh()->status, "{$case}: the task moved");
            $this->assertSame(TaskStatus::Pending, $hidden->refresh()->status, "{$case}: the hidden task moved");
        }

        // In progress → Pending has no road in the workflow (a started task goes back only through complete/cancel and reopen).
        $this->tasks->start($this->task, $this->employee);
        $this->statusBoard($this->employee)
            ->call('moveTask', $this->task->getKey(), 'in_progress', 'pending');
        $this->assertSame(__('tasks.board_ux.notifications.no_move', ['from' => TaskStatus::InProgress->getLabel(), 'to' => TaskStatus::Pending->getLabel()]), $this->refusalBody());
        $this->assertSame(TaskStatus::InProgress, $this->task->refresh()->status);

        // A card the view never draws cannot be dragged: a cancelled task, a completion from before this week.
        $cancelled = $this->taskAssignedTo($this->admin, TaskStatus::Cancelled, ['title' => 'Called off']);
        $this->statusBoard($this->admin)->call('moveTask', $cancelled->getKey(), 'cancelled', 'pending');
        $this->assertSame(__('tasks.board_ux.notifications.not_a_column'), $this->refusalBody(), 'a drag from the cancelled status');
        $this->assertSame(TaskStatus::Cancelled, $cancelled->refresh()->status, 'a forged drag never reopens a cancelled task');

        $lastWeek = $this->taskAssignedTo($this->admin, TaskStatus::Completed, ['title' => 'Done last week', 'completed_at' => '2026-10-01 12:00:00']);
        $this->statusBoard($this->admin)->call('moveTask', $lastWeek->getKey(), 'completed', 'pending');
        $this->assertSame(__('tasks.board_ux.notifications.not_a_column'), $this->refusalBody(), 'a drag of a completion from before this week');
        $this->assertSame(TaskStatus::Completed, $lastWeek->refresh()->status);

        // A drop sent while the by-assignee view is shown is not a status move.
        Livewire::actingAs($this->employee)->test(TasksBoard::class)
            ->set('boardView', TasksBoard::VIEW_ASSIGNEE)
            ->call('moveTask', $this->task->getKey(), 'in_progress', 'completed');
        $this->assertSame(__('tasks.board_ux.notifications.not_a_column'), $this->refusalBody());
        $this->assertSame(TaskStatus::InProgress, $this->task->refresh()->status);
    }

    #[Test]
    public function the_board_header_and_the_sidebar_badge_call_overdue_what_my_work_calls_overdue(): void
    {
        // 10:00 now: a task due at 09:00 today is «اليوم 09:00» on My Work and the list, not overdue.
        $this->taskAssignedTo($this->admin, TaskStatus::Pending, ['title' => 'Due this morning', 'due_at' => '2026-10-07 09:00:00']);

        $stats = app(TaskBoardFeed::class)->stats();
        $this->assertSame(0, $stats['overdue']);
        $this->assertSame(1, $stats['due_today']);

        $this->actingAs($this->admin);
        $this->assertSame('1', TaskResource::getNavigationBadge());
        $this->assertSame('warning', TaskResource::getNavigationBadgeColor(), 'due today, not overdue');

        $this->taskAssignedTo($this->admin, TaskStatus::Pending, ['title' => 'Due yesterday', 'due_at' => '2026-10-06 23:00:00']);
        $this->assertSame(1, app(TaskBoardFeed::class)->stats()['overdue']);
        $this->assertSame('danger', TaskResource::getNavigationBadgeColor());
    }

    #[Test]
    public function forged_action_mounts_are_refused_on_the_server(): void
    {
        $hidden = $this->taskAssignedTo($this->admin, TaskStatus::Pending, ['title' => 'Admin private task']);

        // The participant may not start; the employee may not cancel a handed-out task; nobody reaches a hidden task.
        $this->statusBoard($this->participant)->call('mountAction', 'statusStart', ['task' => $this->task->getKey()]);
        $this->statusBoard($this->employee)->call('mountAction', 'statusCancel', ['task' => $this->task->getKey()]);
        $this->statusBoard($this->employee)->call('mountAction', 'statusStart', ['task' => $hidden->getKey()]);
        $this->statusBoard($this->employee)->call('mountAction', 'statusComplete', ['task' => 'garbage']);

        $this->assertSame(TaskStatus::Pending, $this->task->refresh()->status);
        $this->assertSame(TaskStatus::Pending, $hidden->refresh()->status);
    }

    #[Test]
    public function my_tasks_only_narrows_both_views_and_the_board_remembers_the_readers_choice(): void
    {
        $this->taskAssignedTo($this->participant, TaskStatus::Pending, ['title' => 'Participant chore']);
        $this->taskAssignedTo($this->admin, TaskStatus::Pending, ['title' => 'Admin own chore']);

        $board = Livewire::actingAs($this->admin)->test(TasksBoard::class)
            ->assertSet('boardView', TasksBoard::VIEW_ASSIGNEE)
            ->assertSee('Participant chore')
            ->assertSee('Admin own chore')
            ->set('mine', true)
            ->assertSee('Admin own chore')
            ->assertDontSee('Participant chore')
            ->assertDontSee('Prepare the visit');

        $board->set('boardView', TasksBoard::VIEW_STATUS)
            ->assertSee('Admin own chore')
            ->assertDontSee('Participant chore')
            ->set('mine', false)
            ->assertSee('Participant chore');

        // The next visit opens on the by-status view the reader left, unless the address says otherwise.
        Livewire::actingAs($this->admin)->test(TasksBoard::class)->assertSet('boardView', TasksBoard::VIEW_STATUS);
        Livewire::withQueryParams(['view' => 'assignee'])->actingAs($this->admin)->test(TasksBoard::class)->assertSet('boardView', TasksBoard::VIEW_ASSIGNEE);
        Livewire::withQueryParams(['view' => 'nonsense'])->actingAs($this->admin)->test(TasksBoard::class)->assertSet('boardView', TasksBoard::VIEW_ASSIGNEE);
    }

    #[Test]
    public function the_empty_states_name_one_next_step_in_both_locales(): void
    {
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $newcomer = $this->makeUser(CrmRole::Employee, ['locale' => $locale]);
            app()->setLocale($locale);

            Livewire::actingAs($newcomer)->test(TasksBoard::class)
                ->set('boardView', TasksBoard::VIEW_ASSIGNEE)
                ->set('mine', true)
                ->assertSee(__('tasks.board_ux.empty.board_mine'))
                ->assertSee(__('tasks.board_ux.actions.show_everyone'))
                ->assertSee(__('tasks.filters.mine'))
                ->set('boardView', TasksBoard::VIEW_STATUS)
                ->assertSee(__('tasks.board_ux.empty.column_mine'))
                ->assertSee(__('tasks.board_ux.views.status'))
                ->assertSee(__('tasks.board_ux.helpers.status_view'));

            $this->actingAs($newcomer)->get(TasksBoard::getUrl())
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    #[Test]
    public function an_undo_elsewhere_redraws_the_board(): void
    {
        $board = $this->statusBoard($this->employee);
        $revision = $board->get('revision');

        $board->dispatch(TaskActions::CHANGED_EVENT);

        $this->assertGreaterThan($revision, $board->get('revision'));
    }

    #[Test]
    public function the_by_status_view_issues_no_per_card_queries(): void
    {
        // Every column holds a card from the start: a column's eager loads run once it has cards, whatever their number.
        $this->taskAssignedTo($this->employee, TaskStatus::InProgress, ['title' => 'Small started']);
        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['title' => 'Small done', 'completed_at' => '2026-10-05 09:00:00']);

        $render = fn () => $this->statusBoard($this->admin)->assertOk();
        $render();
        $small = $this->queriesDuring($render);

        foreach (range(1, 12) as $index) {
            $task = $this->tasks->create(['title' => "Large {$index}", 'assignee_id' => $this->participant->getKey()], $this->admin);
            $this->checklistItemOn($task, 'Step');
        }
        $this->taskAssignedTo($this->employee, TaskStatus::InProgress);
        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['completed_at' => '2026-10-06 09:00:00']);

        $large = $this->queriesDuring($render);

        $this->assertLessThanOrEqual(
            count($small) + 3,
            count($large),
            sprintf("by-status board: %d queries for 3 cards, %d for 17. Most repeated:\n%s", count($small), count($large), $this->topRepeated($large)),
        );
    }

    private function statusBoard(User $user): Testable
    {
        $this->actingAs($user);

        return Livewire::actingAs($user)->test(TasksBoard::class)->set('boardView', TasksBoard::VIEW_STATUS);
    }

    /**
     * The body of the refusal toast the last drop sent — read the way the
     * page's toast area reads it (which consumes the sent toasts), or null
     * when no refusal was sent.
     */
    private function refusalBody(): ?string
    {
        $area = new Notifications;
        $area->mount();

        $refusal = $area->notifications
            ->filter(static fn (Notification $toast): bool => $toast->getTitle() === __('tasks.board_ux.notifications.refused'))
            ->last();

        return $refusal?->getBody();
    }
}
