<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
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
 * query (MySQL 8), so the cost never grows per card, and the subject
 * relations are eager-loaded because the page's `view` policy checks walk
 * them. Only non-deleted tasks appear.
 *
 * The header stats fold "today" and "this week" in the organisation timezone
 * in PHP (A-19): the bounds are computed here and the SQL compares only the
 * raw stored columns, never a SQL date function.
 */
final class TaskBoardFeed
{
    /** The most cards one column shows; the rest becomes a "+n more" counter. */
    public const int TASKS_PER_ASSIGNEE = 8;

    /** The most cards the whole board renders in one response. */
    public const int MAX_TASKS = 200;

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * The organisation-wide header figures: open and in-progress totals,
     * what is overdue right now, what falls due during the organisation's
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
            'overdue' => Task::query()->open()->whereNotNull('due_at')->where('due_at', '<', $now)->count(),
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
     * @return array{columns: list<array{assignee: User|null, tasks: list<Task>, total: int, more: int}>, truncated: bool}
     */
    public function board(): array
    {
        $totals = $this->openTotalsPerAssignee();

        /** @var array<string, array{key: string, assignee: User|null, tasks: list<Task>}> $buckets */
        $buckets = [];

        foreach ($this->topTasksPerAssignee() as $task) {
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

    /** The organisation timezone, for showing a stored moment as the organisation reads it. */
    public function timezone(): string
    {
        return $this->settings->timezone();
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
    private function topTasksPerAssignee(): EloquentCollection
    {
        $ranked = DB::table('tasks')
            ->whereNull('deleted_at')
            ->whereIn('status', Task::openStatusValues())
            ->select('id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY `assignee_id` ORDER BY `due_at` IS NULL, `due_at`, `id`) AS `bucket_position`');

        $heads = DB::table($ranked, 'ranked')
            ->where('bucket_position', '<=', self::TASKS_PER_ASSIGNEE)
            ->select('id');

        $tasks = Task::query()
            ->joinSub($heads, 'ranked', 'ranked.id', '=', 'tasks.id')
            ->with(['deal', 'lead', 'contact', 'account'])
            ->select('tasks.*')
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
    private function openTotalsPerAssignee(): array
    {
        $totals = [];

        $rows = Task::query()
            ->open()
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
