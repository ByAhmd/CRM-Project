<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\NavigationGroup;
use App\Enums\TaskStatus;
use App\Enums\TaskTransition;
use App\Filament\Concerns\BuildsPageUrlsInOwningPanel;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\TaskActions;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskBoardFeed;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * The tasks board (decisions D-14, D-27), in two views and a «مهامي فقط»
 * (mine only) switch that narrows either one to the reader's own tasks.
 *
 * - By assignee (D-14): every user's open tasks, grouped by assignee,
 *   visible to anyone holding `task.view_any` — the one page-scoped
 *   exception to D-4's own/team visibility: the whole team sees who carries
 *   what. The cards link to a task only where the reader's ordinary `view`
 *   policy allows, and offer a status move only where the policy allows it.
 * - By status (D-27): the tasks the reader may open, in Pending · In
 *   progress · Completed this week (cancelled tasks stay off the board).
 *   Dragging a card to another column asks for the same transition the
 *   status pill offers: moveTask() re-reads the task from the readable
 *   query, refuses a stale drop (the card's status changed meanwhile), a
 *   move the workflow has no road for (In progress → Pending) and a move
 *   the policy refuses, each with a message, and otherwise mounts the
 *   foundation's TaskActions action for that move — which authorises
 *   again and writes through TaskService; a drop on Completed with open
 *   checklist items opens the D-25 warning first. Every drop bumps
 *   $revision, so the columns re-render from the database and a refused
 *   card snaps back. The status pill on every card is the keyboard
 *   alternative to dragging.
 *
 * The querying and shaping live in TaskBoardFeed; the chosen view and the
 * switch live in the address (and in the session, so the board opens as
 * the reader left it).
 */
final class TasksBoard extends Page
{
    use BuildsPageUrlsInOwningPanel;

    public const string VIEW_ASSIGNEE = 'assignee';

    public const string VIEW_STATUS = 'status';

    public const array VIEWS = [self::VIEW_ASSIGNEE, self::VIEW_STATUS];

    /** The session key the reader's last view and switch are kept under. */
    private const string SESSION_KEY = 'crm.tasks_board';

    /** The foundation action that performs each move (TaskActions). */
    private const array MOVE_ACTIONS = [
        'start' => 'statusStart',
        'complete' => 'statusComplete',
        'reopen' => 'statusReopen',
        'cancel' => 'statusCancel',
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.pages.tasks-board';

    #[Url(as: 'view', except: self::VIEW_ASSIGNEE)]
    public string $boardView = self::VIEW_ASSIGNEE;

    #[Url(as: 'mine', except: false)]
    public bool $mine = false;

    /** Bumped by every drop and every change elsewhere, so the columns re-render from the database. */
    #[Locked]
    public int $revision = 0;

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Tasks;
    }

    public static function getNavigationLabel(): string
    {
        return __('tasks.pages.board.navigation');
    }

    public function getTitle(): string
    {
        return __('tasks.pages.board.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Task::class);
    }

    /** Opens the board as the reader left it, unless the address says otherwise. */
    public function mount(): void
    {
        $remembered = session(self::SESSION_KEY);
        $remembered = is_array($remembered) ? $remembered : [];

        if (! request()->query->has('view') && is_string($remembered['view'] ?? null)) {
            $this->boardView = $remembered['view'];
        }

        if (! request()->query->has('mine') && is_bool($remembered['mine'] ?? null)) {
            $this->mine = $remembered['mine'];
        }

        $this->boardView = self::normaliseView($this->boardView);
        $this->remember();
    }

    public function updatedBoardView(mixed $value): void
    {
        $this->boardView = self::normaliseView($value);
        $this->remember();
    }

    public function updatedMine(): void
    {
        $this->remember();
    }

    /** A «تراجع» (undo) reopened a task: draw the columns again. */
    #[On(TaskActions::CHANGED_EVENT)]
    public function refreshTasks(): void
    {
        $this->revision++;
    }

    /**
     * A card dropped on another column of the by-status view. Everything the
     * browser sends is checked again: the task must be one the reader may
     * open, still in the column it was dragged from — a column it has a card
     * in (never a cancelled task, nor one completed before this week) — the
     * target column must be on the board, the workflow must lead there and the policy must
     * allow the move. A refusal says why and leaves the task as it was; an
     * allowed move runs the same action the status pill runs.
     */
    public function moveTask(mixed $taskId, mixed $from, mixed $to): void
    {
        $this->revision++;

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $origin = is_string($from) ? TaskStatus::tryFrom($from) : null;
        $target = is_string($to) ? TaskStatus::tryFrom($to) : null;

        // Both ends must be columns of the view: a card dragged from nowhere
        // (a cancelled task, a completion from before this week) is forged.
        if ($this->boardView !== self::VIEW_STATUS
            || $target === null || ! in_array($target, TaskBoardFeed::STATUS_COLUMNS, true)
            || $origin === null || ! in_array($origin, TaskBoardFeed::STATUS_COLUMNS, true)) {
            $this->refuse(__('tasks.board_ux.notifications.not_a_column'));

            return;
        }

        $task = is_numeric($taskId) ? TaskResource::getEloquentQuery()->find((int) $taskId) : null;

        if (! $task instanceof Task) {
            $this->refuse(__('tasks.board_ux.notifications.not_found'));

            return;
        }

        if ($task->status !== $origin) {
            $this->refuse(__('tasks.board_ux.notifications.stale', ['status' => $task->status->getLabel()]));

            return;
        }

        if (! app(TaskBoardFeed::class)->isOnStatusBoard($task)) {
            $this->refuse(__('tasks.board_ux.notifications.not_a_column'));

            return;
        }

        if ($origin === $target) {
            return;
        }

        $move = TaskTransition::towards($target, $task);

        if ($move === null) {
            $this->refuse(__('tasks.board_ux.notifications.no_move', ['from' => $origin->getLabel(), 'to' => $target->getLabel()]));

            return;
        }

        if (! $user->can($move->ability(), $task)) {
            $this->refuse(__('tasks.board_ux.notifications.not_allowed', ['move' => $move->getLabel()]));

            return;
        }

        $this->mountAction(self::moveAction($move), ['task' => (int) $task->getKey()]);
    }

    public function statusStartAction(): Action
    {
        return TaskActions::statusStart()->record(fn (array $arguments): ?Task => $this->taskFromArguments($arguments));
    }

    public function statusCompleteAction(): Action
    {
        return TaskActions::statusComplete()->record(fn (array $arguments): ?Task => $this->taskFromArguments($arguments));
    }

    public function statusReopenAction(): Action
    {
        return TaskActions::statusReopen()->record(fn (array $arguments): ?Task => $this->taskFromArguments($arguments));
    }

    public function statusCancelAction(): Action
    {
        return TaskActions::statusCancel()->record(fn (array $arguments): ?Task => $this->taskFromArguments($arguments));
    }

    /** The name of the page action that performs the move. */
    public static function moveAction(TaskTransition $move): string
    {
        return self::MOVE_ACTIONS[$move->value];
    }

    /**
     * Whether a card can be dragged: one of the reader's moves leads to a
     * column on the board (cancelling has no column; it stays on the pill).
     *
     * @param  list<TaskTransition>  $moves
     */
    public static function draggable(array $moves): bool
    {
        foreach ($moves as $move) {
            if (in_array($move->targetStatus(), TaskBoardFeed::STATUS_COLUMNS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $feed = app(TaskBoardFeed::class);
        $reader = auth()->user();
        $assignee = $this->mine && $reader instanceof User ? $reader : null;

        if ($this->boardView === self::VIEW_STATUS) {
            return [
                'statusColumns' => $feed->byStatus(TaskResource::getEloquentQuery(), $assignee),
                'stats' => null,
                'columns' => [],
                'truncated' => false,
            ];
        }

        $board = $feed->board($assignee);

        return [
            'statusColumns' => [],
            'stats' => $feed->stats(),
            'columns' => $board['columns'],
            'truncated' => $board['truncated'],
        ];
    }

    private static function normaliseView(mixed $value): string
    {
        return is_string($value) && in_array($value, self::VIEWS, true) ? $value : self::VIEW_ASSIGNEE;
    }

    private function remember(): void
    {
        session()->put(self::SESSION_KEY, ['view' => $this->boardView, 'mine' => $this->mine]);
    }

    private function refuse(string $reason): void
    {
        Notification::make()
            ->title(__('tasks.board_ux.notifications.refused'))
            ->body($reason)
            ->danger()
            ->send();
    }

    /**
     * The task an action was mounted for, from the readable query only — a
     * forged id or a task outside the reader's reach resolves to nothing.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function taskFromArguments(array $arguments): ?Task
    {
        $taskId = $arguments['task'] ?? null;

        if (! is_numeric($taskId)) {
            return null;
        }

        $task = TaskResource::getEloquentQuery()->find((int) $taskId);

        return $task instanceof Task ? $task : null;
    }
}
