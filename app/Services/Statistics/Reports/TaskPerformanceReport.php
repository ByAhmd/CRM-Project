<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use App\Enums\TaskStatus;
use App\Enums\VisibilityLevel;
use App\Models\Task;
use App\Models\TaskCoAssignee;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Task performance (module 23, decision A-10): per assignee, the tasks
 * completed in the period (by completed_at), how many of them were on time
 * (completed no later than their due date, or without a due date) or late,
 * the tasks created in the period that are still open, the open tasks that
 * are overdue right now, the completion rate — completed over completed
 * plus still open — and the average time to finish (D-21).
 *
 * A line counts assignments, not tasks (D-28). A shared task has several
 * assignees — its first (`tasks.assignee_id`) and each co-assignee
 * (`task_co_assignees`) — and it counts on the line of every one of them,
 * because each of them answers for it: a task shared by three and completed
 * is three completions, one on each line, and the totals line, which sums
 * the lines, counts three as well. The totals are per-assignee
 * accountability, not a count of distinct tasks. A task nobody holds at all
 * (no first assignee and no co-assignee) forms the "unassigned" line; a
 * task whose first assignee was removed while a co-assignee still holds it
 * is that co-assignee's. joinAssignments() is the one definition of an
 * assignment (the ASSIGNMENTS derived table, a UNION ALL joined to tasks),
 * which the employee dashboard joins for one employee (D-26).
 *
 * The time to finish of an assignment completed in the period runs from when
 * the task reached that assignee — the task's `assigned_at` (restamped on
 * reassignment) for the first assignee, the row's own `assigned_at` for a
 * co-assignee, however much later they joined — to the task's completion,
 * from its creation when no assignment moment is recorded (an unassigned
 * task), and is never negative: a task completed before it reached someone
 * counts zero for them. Each row carries the summed seconds
 * (`finish_seconds`, no column) beside its completed count, so the totals
 * line is the mean over every completed assignment, not a mean of the
 * per-assignee means; a row without a completed assignment shows no average.
 * Every figure comes from one aggregate statement; TIMESTAMPDIFF, GREATEST
 * and COALESCE read the same on MySQL 8 and MariaDB (A-21).
 *
 * Scope (D-4, D-13): an assignment counts when its task is visible to the
 * viewer (RecordVisibilityResolver::visible(), which reaches a shared task
 * through any of its assignees) and the viewer reaches its assignee for
 * tasks (visibleOwners(): everyone at all level; the viewer and their team's
 * members at team level, plus the unassigned line; the viewer alone at own
 * level). A manager therefore sees their members' lines on a task shared
 * with an outsider and never a line for that outsider, and an employee sees
 * their own line alone. The owner and team filters narrow the assignment's
 * assignee (ReportFilters::narrow(), the owner re-checked against the
 * assignable users). Only assignees with at least one such assignment
 * appear. Cancelled tasks are neither completed nor open.
 *
 * "Overdue right now" compares the due date with the current moment written
 * in the application timezone the columns are stored in (A-19), like every
 * other bound, so it holds whatever timezone the organisation keeps and
 * matches the employee dashboard's overdue count (D-26);
 * EmployeeDashboardFiguresTest guards that the two agree.
 */
final class TaskPerformanceReport
{
    /**
     * The alias of the assignments derived table (D-28): `task_id`, `user_id`
     * (null on a task nobody holds) and `assigned_at`, the moment the task
     * reached that user.
     */
    public const string ASSIGNMENTS = 'assignments';

    /**
     * The time to finish of one completed assignment in seconds (D-21,
     * D-28): from when the task reached the assignment's user — the task's
     * creation when no assignment moment is recorded — to its completion,
     * never negative. Reads a task query joined through joinAssignments();
     * the one expression every reader of the measure uses (this report, the
     * employee dashboard, D-26).
     */
    public const string FINISH_SECONDS_SQL = 'GREATEST(0, TIMESTAMPDIFF(SECOND, COALESCE('.self::ASSIGNMENTS.'.assigned_at, tasks.created_at), tasks.completed_at))';

    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
    ) {}

    /**
     * Joins every assignment of the query's tasks under the ASSIGNMENTS
     * alias (D-28), one row per task and assignee: the first assignee with
     * the task's own assignment moment, each co-assignee with the moment the
     * task reached them, and a task nobody holds once, with no user. Given an
     * assignee, only that user's assignments are joined — exactly the tasks
     * Task::assignedTo() finds, each once (the first assignee is never also a
     * co-assignee). The join keeps the query's own scope: deleted tasks stay
     * out through the outer query.
     *
     * @param  Builder<Task>  $tasks
     * @return Builder<Task>
     */
    public static function joinAssignments(Builder $tasks, ?User $assignee = null): Builder
    {
        return $tasks->joinSub(self::assignments($assignee), self::ASSIGNMENTS, self::ASSIGNMENTS.'.task_id', '=', $tasks->qualifyColumn('id'));
    }

    /**
     * @return Collection<int, ReportRow>
     */
    public function rows(User $viewer, ReportFilters $filters): Collection
    {
        [$from, $to] = $filters->bounds();
        $completed = TaskStatus::Completed->value;
        $open = Task::openStatusValues();
        $openList = implode(', ', array_fill(0, count($open), '?'));
        $assignee = self::ASSIGNMENTS.'.user_id';
        // The current moment written in the timezone the columns are stored
        // in (A-19), as every other bound (ReportFilters::inAppTimezone()).
        $now = CarbonImmutable::now()->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');

        $aggregates = [];

        foreach ($this->query($viewer, $filters)
            ->selectRaw($assignee.' AS assignee_id')
            ->selectRaw('SUM(CASE WHEN tasks.status = ? AND tasks.completed_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS completed', [$completed, $from, $to])
            ->selectRaw('SUM(CASE WHEN tasks.status = ? AND tasks.completed_at BETWEEN ? AND ? AND (tasks.due_at IS NULL OR tasks.completed_at <= tasks.due_at) THEN 1 ELSE 0 END) AS on_time', [$completed, $from, $to])
            ->selectRaw('SUM(CASE WHEN tasks.status = ? AND tasks.completed_at BETWEEN ? AND ? AND tasks.due_at IS NOT NULL AND tasks.completed_at > tasks.due_at THEN 1 ELSE 0 END) AS late', [$completed, $from, $to])
            ->selectRaw("SUM(CASE WHEN tasks.status IN ({$openList}) AND tasks.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS still_open", [...$open, $from, $to])
            ->selectRaw("SUM(CASE WHEN tasks.status IN ({$openList}) AND tasks.due_at < ? THEN 1 ELSE 0 END) AS overdue_now", [...$open, $now])
            ->selectRaw('SUM(CASE WHEN tasks.status = ? AND tasks.completed_at BETWEEN ? AND ? THEN '.self::FINISH_SECONDS_SQL.' ELSE 0 END) AS finish_seconds', [$completed, $from, $to])
            ->groupBy($assignee)
            ->toBase()
            ->get() as $aggregate) {
            $done = (int) $aggregate->completed;
            $stillOpen = (int) $aggregate->still_open;
            $finishSeconds = (int) $aggregate->finish_seconds;

            $aggregates[(int) ($aggregate->assignee_id ?? 0)] = [
                'completed' => $done,
                'on_time' => (int) $aggregate->on_time,
                'late' => (int) $aggregate->late,
                'still_open' => $stillOpen,
                'overdue_now' => (int) $aggregate->overdue_now,
                'completion_rate' => ReportRow::rate($done, $done + $stillOpen),
                'avg_time_to_finish' => ReportRow::meanSeconds($finishSeconds, $done),
                'finish_seconds' => $finishSeconds,
            ];
        }

        $rows = new Collection;

        foreach (User::query()->withTrashed()->whereIn('id', array_filter(array_keys($aggregates)))->orderBy('name')->get() as $user) {
            $rows->push(new ReportRow($user->name, $aggregates[(int) $user->getKey()], ['assignee_id' => (int) $user->getKey()]));
        }

        if (isset($aggregates[0])) {
            $rows->push(new ReportRow(__('reports.labels.unassigned'), $aggregates[0], ['assignee_id' => null]));
        }

        return $rows;
    }

    /**
     * Sums of the lines — a shared task counted once per assignee (D-28) —
     * with the completion rate recomputed and the time to finish averaged
     * over every completed assignment rather than every assignee.
     *
     * @param  Collection<int, ReportRow>  $rows
     */
    public function totals(Collection $rows): ReportRow
    {
        $totals = ReportRow::totals(__('reports.totals'), $rows, ['completed', 'on_time', 'late', 'still_open', 'overdue_now']);
        $finishSeconds = ReportRow::sum($rows, 'finish_seconds');

        return new ReportRow($totals->label, [
            ...$totals->values,
            'completion_rate' => ReportRow::rate($totals->number('completed'), $totals->number('completed') + $totals->number('still_open')),
            'avg_time_to_finish' => ReportRow::meanSeconds($finishSeconds, $totals->number('completed')),
            'finish_seconds' => $finishSeconds,
        ]);
    }

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int|float>, color: string}>}
     */
    public function chart(User $viewer, ReportFilters $filters): array
    {
        return $this->chartFromRows($this->rows($viewer, $filters));
    }

    /**
     * The same chart from rows already fetched, so a page render does not
     * repeat the aggregates (D-1).
     *
     * @param  Collection<int, ReportRow>  $rows
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int|float>, color: string}>}
     */
    public function chartFromRows(Collection $rows): array
    {

        return [
            'labels' => $rows->map(static fn (ReportRow $row): string => $row->label)->values()->all(),
            'datasets' => [
                ['label' => __('reports.chart.completed'), 'data' => $rows->map(static fn (ReportRow $row): int => (int) $row->value('completed'))->values()->all(), 'color' => 'primary'],
                ['label' => __('reports.chart.on_time'), 'data' => $rows->map(static fn (ReportRow $row): int => (int) $row->value('on_time'))->values()->all(), 'color' => 'success'],
                ['label' => __('reports.chart.late'), 'data' => $rows->map(static fn (ReportRow $row): int => (int) $row->value('late'))->values()->all(), 'color' => 'danger'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'completed' => __('reports.columns.task.completed'),
            'on_time' => __('reports.columns.task.on_time'),
            'late' => __('reports.columns.task.late'),
            'still_open' => __('reports.columns.task.still_open'),
            'overdue_now' => __('reports.columns.task.overdue_now'),
            'completion_rate' => __('reports.columns.task.completion_rate'),
            'avg_time_to_finish' => __('reports.columns.task.avg_time_to_finish'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function formats(): array
    {
        return [
            'completed' => ReportRow::FORMAT_COUNT,
            'on_time' => ReportRow::FORMAT_COUNT,
            'late' => ReportRow::FORMAT_COUNT,
            'still_open' => ReportRow::FORMAT_COUNT,
            'overdue_now' => ReportRow::FORMAT_COUNT,
            'completion_rate' => ReportRow::FORMAT_PERCENT,
            'avg_time_to_finish' => ReportRow::FORMAT_DURATION,
        ];
    }

    /**
     * The viewer's assignments: every assignment of a task they may see,
     * whose assignee they reach for tasks, narrowed by the owner and team
     * filters on that assignee.
     *
     * @return Builder<Task>
     */
    private function query(User $viewer, ReportFilters $filters): Builder
    {
        $query = self::joinAssignments($this->visibility->visible($viewer, Task::query()));
        $assignee = self::ASSIGNMENTS.'.user_id';
        $reached = $this->visibility->visibleOwners($viewer, Task::class)->select('users.id');

        $query = match ($this->visibility->levelFor($viewer, Task::class)) {
            VisibilityLevel::All => $query,
            // The team's own lines, and the unassigned line as for any task
            // without an owner at team level (D-4).
            VisibilityLevel::Team => $query->where(static function (Builder $lines) use ($assignee, $reached): void {
                $lines->whereIn($assignee, $reached)->orWhereNull($assignee);
            }),
            VisibilityLevel::Own, VisibilityLevel::None => $query->whereIn($assignee, $reached),
        };

        return $filters->narrow($viewer, $this->visibility, $query, $assignee, Task::permissionGroup());
    }

    /**
     * The assignments as a derived table (see joinAssignments()). The first
     * branch reads the tasks' own assignee — every task with one, and each
     * task nobody holds at all, with a null user; the second reads the
     * co-assignee rows.
     */
    private static function assignments(?User $assignee): QueryBuilder
    {
        $coAssignees = (new TaskCoAssignee)->getTable();

        $first = Task::query()->toBase()
            ->select(['tasks.id as task_id', 'tasks.assignee_id as user_id', 'tasks.assigned_at as assigned_at']);

        $shared = TaskCoAssignee::query()->toBase()
            ->select([$coAssignees.'.task_id as task_id', $coAssignees.'.user_id as user_id', $coAssignees.'.assigned_at as assigned_at']);

        if ($assignee === null) {
            $first->where(static function (QueryBuilder $held) use ($coAssignees): void {
                $held->whereNotNull('tasks.assignee_id')
                    ->orWhereNotExists(TaskCoAssignee::query()->toBase()->selectRaw('1')->whereColumn($coAssignees.'.task_id', 'tasks.id'));
            });
        } else {
            $first->where('tasks.assignee_id', $assignee->getKey());
            $shared->where($coAssignees.'.user_id', $assignee->getKey());
        }

        return $first->unionAll($shared);
    }
}
