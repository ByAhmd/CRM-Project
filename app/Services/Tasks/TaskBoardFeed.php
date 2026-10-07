<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\Settings\OrganisationCalendar;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The shared tasks board read model (decision D-14): who carries what, for
 * everyone.
 *
 * The queries here are deliberately organisation-wide — the one page-scoped
 * exception to D-4's own/team visibility, decided so the whole team sees the
 * workload. The board is display only: the page links a card to its task only
 * where the viewer's ordinary `view` policy allows, and every other task
 * query path stays resolver-scoped.
 *
 * Shape: one column per assignee, ordered by name (Arabic-aware through the
 * connection collation is not needed — PHP sorts the loaded names), with an
 * «unassigned» bucket last. Each column shows the assignee's earliest-due
 * open tasks (nulls last, capped at TASKS_PER_ASSIGNEE) plus how many more it
 * holds; the whole board never renders more than MAX_TASKS cards and says so
 * when the cap cut it. The top-N-per-assignee read is one window-function
 * query (MySQL 8), so the cost never grows per card, the subject
 * relations are eager-loaded because the page's `view` policy checks walk
 * them, and each card's checklist progress (D-25) is counted in the same
 * query. Only non-deleted tasks appear.
 *
 * The header stats fold "today" and "this week" in the organisation timezone
 * in PHP (A-19): the bounds are computed here and the SQL compares only the
 * raw stored columns, never a SQL date function.
 *
 * The by-status view (D-27) is NOT part of that exception: it is where a
 * reader moves tasks, so it reads only the tasks the reader may open — the
 * caller hands in TaskResource's readable query — in three columns,
 * Pending · In progress · Completed this week (the organisation's week);
 * cancelled tasks stay off the board. Each column shows its first
 * CARDS_PER_STATUS cards and counts the rest: two queries per column,
 * whatever the number of cards. «مهامي فقط» (mine only) narrows either view
 * to one assignee.
 */
final class TaskBoardFeed
{
    /** The most cards one column shows; the rest becomes a "+n more" counter. */
    public const int TASKS_PER_ASSIGNEE = 8;

    /** The most cards the whole board renders in one response. */
    public const int MAX_TASKS = 200;

    /** The most cards one by-status column shows; the rest becomes a "+n more" counter. */
    public const int CARDS_PER_STATUS = 50;

    /** The by-status columns, in board order; cancelled tasks are not on the board (D-27). */
    public const array STATUS_COLUMNS = [
        TaskStatus::Pending,
        TaskStatus::InProgress,
        TaskStatus::Completed,
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly OrganisationCalendar $calendar,
    ) {}

    /**
     * The organisation-wide header figures: open and in-progress totals,
     * what was due before the organisation's today, what falls due during the organisation's
     * today, and what was completed since the organisation's week began.
     *
     * @return array{open: int, in_progress: int, overdue: int, due_today: int, completed_this_week: int}
     */
    public function stats(): array
    {
        $now = now();
        $today = $now->copy()->setTimezone($this->settings->timezone())->toDateString();

        return [
            'open' => Task::query()->open()->count(),
            'in_progress' => Task::query()->where('status', TaskStatus::InProgress->value)->count(),
            // Overdue: due before the organisation's today began — the meaning My Work, the list and the due words share (D-27).
            'overdue' => Task::query()->open()->whereNotNull('due_at')->where('due_at', '<', $this->calendar->stored($this->calendar->today()))->count(),
            'due_today' => Task::query()->open()->dueBetween(
                $this->settings->startOfOrganisationDay($today),
                $this->settings->endOfOrganisationDay($today),
            )->count(),
            'completed_this_week' => Task::query()
                ->where('status', TaskStatus::Completed->value)
                ->where('completed_at', '>=', $this->startOfOrganisationWeek($now))
                ->count(),
        ];
    }

    /**
     * The columns of the board: every assignee with open tasks, by name, the
     * unassigned bucket last, each carrying its earliest-due cards and the
     * count left behind — cut to the board-wide card budget.
     *
     * With an assignee («مهامي فقط», D-27) the board holds that one column.
     *
     * @return array{columns: list<array{assignee: User|null, tasks: list<Task>, total: int, more: int}>, truncated: bool}
     */
    public function board(?User $assignee = null): array
    {
        $assigneeId = $assignee === null ? null : (int) $assignee->getKey();
        $totals = $this->openTotalsPerAssignee($assigneeId);

        /** @var array<string, array{key: string, assignee: User|null, tasks: list<Task>}> $buckets */
        $buckets = [];

        foreach ($this->topTasksPerAssignee($assigneeId) as $task) {
            $key = (string) ($task->assignee_id ?? '');

            if (! array_key_exists($key, $buckets)) {
                $buckets[$key] = ['key' => $key, 'assignee' => $task->assignee, 'tasks' => []];
            }

            $buckets[$key]['tasks'][] = $task;
        }

        $ordered = array_values($buckets);

        usort($ordered, static function (array $a, array $b): int {
            // Named assignees alphabetically — a deleted user's open tasks
            // keep their own column under that user's name — and the
            // unassigned bucket after them.
            $rank = ($a['assignee'] === null ? 1 : 0) <=> ($b['assignee'] === null ? 1 : 0);

            if ($rank !== 0) {
                return $rank;
            }

            $left = $a['assignee'] === null ? '' : mb_strtolower($a['assignee']->name);
            $right = $b['assignee'] === null ? '' : mb_strtolower($b['assignee']->name);

            return $left <=> $right;
        });

        $budget = self::MAX_TASKS;
        $columns = [];
        $truncated = false;

        foreach ($ordered as $bucket) {
            if ($budget <= 0) {
                $truncated = true;
                break;
            }

            $visible = array_slice($bucket['tasks'], 0, $budget);

            if (count($visible) < count($bucket['tasks'])) {
                $truncated = true;
            }

            $budget -= count($visible);
            $total = $totals[$bucket['key']] ?? count($bucket['tasks']);

            $columns[] = [
                'assignee' => $bucket['assignee'],
                'tasks' => $visible,
                'total' => $total,
                'more' => max(0, $total - count($visible)),
            ];
        }

        return ['columns' => $columns, 'truncated' => $truncated];
    }

    /**
     * The by-status view (D-27): the readable tasks in the STATUS_COLUMNS
     * order — the open columns by due date (no date last), the completed
     * column newest first and limited to the organisation's current week —
     * each with its first CARDS_PER_STATUS cards, their checklist counts
     * (D-25) and its total. The readable query brings the assignee and the
     * subject relations the cards and their policy checks read.
     *
     * @param  Builder<Task>  $readable  TaskResource::getEloquentQuery() — never an unscoped query
     * @return list<array{status: TaskStatus, tasks: list<Task>, total: int, more: int}>
     */
    public function byStatus(Builder $readable, ?User $assignee = null): array
    {
        $columns = [];

        foreach (self::STATUS_COLUMNS as $status) {
            $query = (clone $readable)->where('tasks.status', $status->value);

            if ($assignee !== null) {
                $query->where('tasks.assignee_id', $assignee->getKey());
            }

            if ($status === TaskStatus::Completed) {
                $query->where('tasks.completed_at', '>=', $this->calendar->stored($this->calendar->startOfWeek()));
            }

            $total = (clone $query)->count();

            $cards = TaskChecklist::withCounts($query);

            if ($status === TaskStatus::Completed) {
                $cards->orderByDesc('tasks.completed_at')->orderByDesc('tasks.id');
            } else {
                $cards->orderByRaw('`tasks`.`due_at` IS NULL')->orderBy('tasks.due_at')->orderBy('tasks.id');
            }

            $tasks = array_values($cards->limit(self::CARDS_PER_STATUS)->get()->all());

            $columns[] = [
                'status' => $status,
                'tasks' => $tasks,
                'total' => $total,
                'more' => max(0, $total - count($tasks)),
            ];
        }

        return $columns;
    }

    /**
     * Whether the task has a card on the by-status view: an open status
     * column, or completed since the organisation's week began.
     */
    public function isOnStatusBoard(Task $task): bool
    {
        if (! in_array($task->status, self::STATUS_COLUMNS, true)) {
            return false;
        }

        return $task->status !== TaskStatus::Completed
            || ($task->completed_at !== null && $task->completed_at->greaterThanOrEqualTo($this->calendar->stored($this->calendar->startOfWeek())));
    }

    /**
     * Every assignee's earliest-due open tasks, at most TASKS_PER_ASSIGNEE
     * each, in one window-function query: the subquery ranks the open tasks
     * inside each assignee bucket by due date (nulls last, ties by id) and
     * the outer query hydrates only the ranked heads, with the assignee and
     * the subject relations the card and its `view` policy check need. The
     * assignees come in one further query that includes soft-deleted users:
     * a deleted user's tasks still carry their id (a soft delete does not
     * fire the column's nullOnDelete), and without the user they would pose
     * as a second «unassigned» column.
     *
     * @return EloquentCollection<int, Task>
     */
    private function topTasksPerAssignee(?int $assigneeId): EloquentCollection
    {
        $ranked = DB::table('tasks')
            ->whereNull('deleted_at')
            ->whereIn('status', Task::openStatusValues())
            ->when($assigneeId !== null, static fn ($query) => $query->where('assignee_id', $assigneeId))
            ->select('id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY `assignee_id` ORDER BY `due_at` IS NULL, `due_at`, `id`) AS `bucket_position`');

        $heads = DB::table($ranked, 'ranked')
            ->where('bucket_position', '<=', self::TASKS_PER_ASSIGNEE)
            ->select('id');

        // The checklist counts (D-25) come after the select: a select() after
        // withCount() would drop them.
        $tasks = TaskChecklist::withCounts(
            Task::query()
                ->joinSub($heads, 'ranked', 'ranked.id', '=', 'tasks.id')
                ->with(['deal', 'lead', 'contact', 'account'])
                ->select('tasks.*'),
        )
            ->orderByRaw('`tasks`.`due_at` IS NULL')
            ->orderBy('tasks.due_at')
            ->orderBy('tasks.id')
            ->get();

        $assigneeIds = $tasks->pluck('assignee_id')->filter()->unique()->values()->all();

        $assignees = $assigneeIds === []
            ? new EloquentCollection
            : User::withTrashed()->whereKey($assigneeIds)->get()->keyBy(static fn (User $user): int => (int) $user->getKey());

        foreach ($tasks as $task) {
            $task->setRelation('assignee', $task->assignee_id === null ? null : $assignees->get((int) $task->assignee_id));
        }

        return $tasks;
    }

    /**
     * How many open tasks each assignee holds, keyed by assignee id ('' for
     * the unassigned bucket), so a column can say what the cap hid.
     *
     * @return array<string, int>
     */
    private function openTotalsPerAssignee(?int $assigneeId): array
    {
        $totals = [];

        $rows = Task::query()
            ->open()
            ->when($assigneeId !== null, static fn (Builder $query): Builder => $query->where('assignee_id', $assigneeId))
            ->toBase()
            ->selectRaw('`assignee_id`, COUNT(*) AS `total`')
            ->groupBy('assignee_id')
            ->get();

        foreach ($rows as $row) {
            $totals[(string) ($row->assignee_id ?? '')] = (int) $row->total;
        }

        return $totals;
    }

    /** The first moment of the organisation's current week, in the application timezone the columns are stored in. */
    private function startOfOrganisationWeek(Carbon $now): Carbon
    {
        $organisationNow = $now->copy()->setTimezone($this->settings->timezone());
        $weekStartDate = $organisationNow->startOfWeek($this->settings->weekStartsOn())->toDateString();

        return $this->settings->startOfOrganisationDay($weekStartDate);
    }
}
