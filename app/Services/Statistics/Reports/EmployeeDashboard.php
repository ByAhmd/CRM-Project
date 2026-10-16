<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskCoAssignee;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Settings\SettingsRepository;
use App\Services\Tasks\StalledTaskRule;
use App\Services\Tasks\TaskChecklist;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * The employee dashboard (decision D-26): one employee's task work on one
 * screen, for a reader inside whose task visibility (D-4) the employee lies.
 *
 * Reach: employees() lists the people whose tasks the reader reads by
 * ownership (RecordVisibilityResolver::visibleOwners() on Task — everyone at
 * all level, deleted accounts included; the reader's team at team level;
 * the reader alone at own level) who may hold tasks (`task.view_any`
 * through a role or directly) or carry one — as a task's first assignee or
 * as a co-assignee of a shared task (D-28). employee() admits an id only
 * from that list, and snapshot() refuses anyone outside it — the page never
 * renders a partial dashboard.
 *
 * Figures: the tasks the employee holds (Task::assignedTo(): first assignee
 * or co-assignee, D-28), deleted tasks excluded, read through the reader's
 * own visibility scope. A shared task is the employee's like any other —
 * open, overdue, completed and on time alike — and its time to finish runs
 * from the employee's own assignment moment: the figures join the task
 * report's own assignments (TaskPerformanceReport::joinAssignments()) for
 * the employee, so they read what the employee's line on that report reads.
 * Every boundary — now, today, this week (on the organisation's week
 * start), this month, the eight trend weeks — is computed on the
 * organisation clock and calendar in PHP and bound as a moment in the
 * application timezone the columns are stored in (A-19); no SQL date
 * function is used, and the one expression that is (the time to finish,
 * TaskPerformanceReport::FINISH_SECONDS_SQL) reads alike on MySQL 8 and
 * MariaDB (A-21).
 *
 * - now: open; overdue (open, due before now — the task report's "overdue
 *   now"); due today (open, due during the organisation's today, overdue or
 *   not, as on the tasks board);
 * - performance: completed this week and this month; the on-time rate of
 *   this month's completions (completed no later than due, or without a due
 *   date); their average time to finish, measured exactly as D-21; both
 *   null when nothing was completed this month;
 * - trend: completions per organisation week, the last eight, current last;
 * - needs attention: overdue tasks (oldest due first), stalled tasks under
 *   the weekly summary's own rule (StalledTaskRule, D-18), tasks completed
 *   this month with checklist items left open (D-25, newest first) — each
 *   capped at LIST_LIMIT with its own count for "+n more";
 * - latest activity: the employee's ACTIVITY_LIMIT newest progress entries
 *   and comments on tasks that are not deleted; the text of an entry is
 *   carried only when the reader may open its task (the thread is the
 *   task's, TaskPolicy::view) — otherwise the line names the kind, the
 *   status, the moment and the title as plain text, and no body.
 *
 * Bounded: one grouped aggregate for every figure and the whole trend, one
 * capped list and one count per attention list, one capped activity list,
 * and one query loading the listed tasks for the reader's view check —
 * the same number of queries whatever the employee's workload. Read-only:
 * nothing is written and nothing is audited.
 */
final class EmployeeDashboard
{
    /** The most tasks each "needs attention" list names. */
    public const int LIST_LIMIT = 10;

    /** The most entries the latest activity shows. */
    public const int ACTIVITY_LIMIT = 10;

    /** How many organisation weeks the trend covers, the current one included. */
    public const int TREND_WEEKS = 8;

    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * The employees the reader may open a dashboard for, by name.
     *
     * @return Builder<User>
     */
    public function employees(User $viewer): Builder
    {
        $viewAny = Task::permissionGroup().'.view_any';

        return $this->visibility->visibleOwners($viewer, Task::class)
            ->where(static function (Builder $holders) use ($viewAny): void {
                $holders
                    ->whereHas('permissions', static fn (Builder $permission): Builder => $permission->where($permission->qualifyColumn('name'), $viewAny))
                    ->orWhereHas('roles.permissions', static fn (Builder $permission): Builder => $permission->where($permission->qualifyColumn('name'), $viewAny))
                    ->orWhereIn('users.id', Task::query()->select('assignee_id')->whereNotNull('assignee_id'))
                    // A co-assignee of a task that is not deleted carries it too (D-28).
                    ->orWhereIn('users.id', TaskCoAssignee::query()->select('task_co_assignees.user_id')->whereIn('task_co_assignees.task_id', Task::query()->select('tasks.id')));
            })
            ->orderBy('users.name')
            ->orderBy('users.id');
    }

    /** The employee with this id when the reader may open their dashboard; null otherwise. */
    public function employee(User $viewer, int $id): ?User
    {
        return $this->employees($viewer)->whereKey($id)->first();
    }

    /**
     * The dashboard of an employee inside the reader's reach.
     *
     * @throws AuthorizationException when the employee lies outside it.
     */
    public function snapshot(User $viewer, User $employee, ?CarbonImmutable $now = null): EmployeeDashboardSnapshot
    {
        if (! $this->employees($viewer)->whereKey($employee->getKey())->exists()) {
            throw new AuthorizationException;
        }

        $now = ($now ?? CarbonImmutable::now())->setTimezone((string) config('app.timezone'));
        $calendar = $this->calendar($now);
        $figures = $this->figures($viewer, $employee, $now, $calendar);

        $overdue = $this->overdueList($viewer, $employee, $now, $figures['overdue']);
        $stalled = $this->stalledList($viewer, $employee, $now);
        $checklistOpen = $this->checklistOpenList($viewer, $employee, $calendar);
        $entries = $this->latestEntries($employee);

        $readable = $this->readableTaskIds($viewer, [
            ...$overdue['ids'],
            ...$stalled['ids'],
            ...$checklistOpen['ids'],
            ...array_map(static fn (TaskUpdate $entry): int => (int) $entry->task_id, $entries),
        ]);

        $timezone = $this->settings->timezone();
        $trend = [];

        foreach ($calendar['weeks'] as $index => $start) {
            $trend[] = ['start' => $start->setTimezone($timezone), 'completed' => $figures['weeks'][$index]];
        }

        $completedThisMonth = $figures['completed_month'];
        $averageSeconds = ReportRow::meanSeconds($figures['finish_seconds_month'], $completedThisMonth);

        return new EmployeeDashboardSnapshot(
            open: $figures['open'],
            overdue: $figures['overdue'],
            dueToday: $figures['due_today'],
            completedThisWeek: $figures['weeks'][self::TREND_WEEKS - 1],
            completedThisMonth: $completedThisMonth,
            onTimeRate: $completedThisMonth > 0 ? ReportRow::rate($figures['on_time_month'], $completedThisMonth) : null,
            // The task report's own mean (D-21); NOT_MEASURED becomes null.
            averageSecondsToFinish: is_int($averageSeconds) ? $averageSeconds : null,
            trend: $trend,
            overdueTasks: $this->list($overdue['lines'], $overdue['total'], $readable),
            stalledTasks: $this->list($stalled['lines'], $stalled['total'], $readable),
            checklistOpenTasks: $this->list($checklistOpen['lines'], $checklistOpen['total'], $readable),
            latestActivity: array_map(fn (TaskUpdate $entry): EmployeeDashboardEntry => $this->entry($entry, $readable, $timezone), $entries),
        );
    }

    /**
     * One latest-activity line. The text of the entry belongs to the task's
     * thread, which opens only to a reader who may open the task
     * (TaskPolicy::view); on any other task the line keeps its kind, status,
     * moment and the title as plain text, and carries no body at all.
     *
     * @param  list<int>  $readable
     */
    private function entry(TaskUpdate $entry, array $readable, string $timezone): EmployeeDashboardEntry
    {
        $isReadable = in_array((int) $entry->task_id, $readable, true);

        return new EmployeeDashboardEntry(
            taskId: (int) $entry->task_id,
            taskTitle: (string) $entry->task?->title,
            kind: $entry->kind,
            status: $entry->status,
            body: $isReadable ? (string) $entry->body : '',
            at: CarbonImmutable::instance($entry->created_at)->setTimezone($timezone),
            readable: $isReadable,
        );
    }

    /**
     * The organisation's boundaries around the moment, each as a moment in
     * the application timezone: today and tomorrow, this month and the next,
     * and the start of each trend week (oldest first) plus the week after
     * the current one.
     *
     * @return array{today: CarbonImmutable, tomorrow: CarbonImmutable, month: CarbonImmutable, next_month: CarbonImmutable, weeks: list<CarbonImmutable>, next_week: CarbonImmutable}
     */
    private function calendar(CarbonImmutable $now): array
    {
        $stored = (string) config('app.timezone');
        $local = $now->setTimezone($this->settings->timezone());
        $today = $local->startOfDay();
        $week = $local->startOfWeek($this->settings->weekStartsOn());
        $month = $local->startOfMonth();

        $weeks = [];

        for ($back = self::TREND_WEEKS - 1; $back >= 0; $back--) {
            $weeks[] = $week->subWeeks($back)->setTimezone($stored);
        }

        return [
            'today' => $today->setTimezone($stored),
            'tomorrow' => $today->addDay()->setTimezone($stored),
            'month' => $month->setTimezone($stored),
            'next_month' => $month->addMonthNoOverflow()->setTimezone($stored),
            'weeks' => $weeks,
            'next_week' => $week->addWeek()->setTimezone($stored),
        ];
    }

    /**
     * Every figure in one grouped aggregate over the employee's open tasks
     * and those completed since the earliest boundary the figures need. The
     * tasks are read as the employee's assignments on the task report
     * (D-28) — the same tasks as tasks(), each once — so a shared task's
     * time to finish runs from when it reached this employee.
     *
     * @param  array{today: CarbonImmutable, tomorrow: CarbonImmutable, month: CarbonImmutable, next_month: CarbonImmutable, weeks: list<CarbonImmutable>, next_week: CarbonImmutable}  $calendar
     * @return array{open: int, overdue: int, due_today: int, completed_month: int, on_time_month: int, finish_seconds_month: int, weeks: list<int>}
     */
    private function figures(User $viewer, User $employee, CarbonImmutable $now, array $calendar): array
    {
        $completed = TaskStatus::Completed->value;
        $open = Task::openStatusValues();
        $openList = implode(', ', array_fill(0, count($open), '?'));
        $moment = static fn (CarbonImmutable $value): string => $value->format('Y-m-d H:i:s');
        $month = [$moment($calendar['month']), $moment($calendar['next_month'])];
        $earliest = $calendar['month']->min($calendar['weeks'][0]);

        $query = TaskPerformanceReport::joinAssignments($this->visibility->visible($viewer, Task::query()), $employee)
            ->where(static function (Builder $relevant) use ($open, $completed, $earliest): void {
                $relevant->whereIn('tasks.status', $open)
                    ->orWhere(static function (Builder $done) use ($completed, $earliest): void {
                        $done->where('tasks.status', $completed)->where('tasks.completed_at', '>=', $earliest);
                    });
            })
            ->selectRaw("SUM(CASE WHEN tasks.status IN ({$openList}) THEN 1 ELSE 0 END) AS open_count", $open)
            ->selectRaw("SUM(CASE WHEN tasks.status IN ({$openList}) AND tasks.due_at < ? THEN 1 ELSE 0 END) AS overdue_count", [...$open, $moment($now)])
            ->selectRaw("SUM(CASE WHEN tasks.status IN ({$openList}) AND tasks.due_at >= ? AND tasks.due_at < ? THEN 1 ELSE 0 END) AS due_today_count", [...$open, $moment($calendar['today']), $moment($calendar['tomorrow'])])
            ->selectRaw('SUM(CASE WHEN tasks.status = ? AND tasks.completed_at >= ? AND tasks.completed_at < ? THEN 1 ELSE 0 END) AS completed_month', [$completed, ...$month])
            ->selectRaw('SUM(CASE WHEN tasks.status = ? AND tasks.completed_at >= ? AND tasks.completed_at < ? AND (tasks.due_at IS NULL OR tasks.completed_at <= tasks.due_at) THEN 1 ELSE 0 END) AS on_time_month', [$completed, ...$month])
            ->selectRaw('SUM(CASE WHEN tasks.status = ? AND tasks.completed_at >= ? AND tasks.completed_at < ? THEN '.TaskPerformanceReport::FINISH_SECONDS_SQL.' ELSE 0 END) AS finish_seconds_month', [$completed, ...$month]);

        $bounds = [...$calendar['weeks'], $calendar['next_week']];

        for ($index = 0; $index < self::TREND_WEEKS; $index++) {
            $query->selectRaw("SUM(CASE WHEN tasks.status = ? AND tasks.completed_at >= ? AND tasks.completed_at < ? THEN 1 ELSE 0 END) AS week_{$index}", [$completed, $moment($bounds[$index]), $moment($bounds[$index + 1])]);
        }

        $row = $query->toBase()->first();
        $value = static fn (string $column): int => (int) ($row->{$column} ?? 0);

        $weeks = [];

        for ($index = 0; $index < self::TREND_WEEKS; $index++) {
            $weeks[] = $value("week_{$index}");
        }

        return [
            'open' => $value('open_count'),
            'overdue' => $value('overdue_count'),
            'due_today' => $value('due_today_count'),
            'completed_month' => $value('completed_month'),
            'on_time_month' => $value('on_time_month'),
            'finish_seconds_month' => $value('finish_seconds_month'),
            'weeks' => $weeks,
        ];
    }

    /**
     * Open tasks past due, oldest due first; the total is the aggregate's
     * overdue count — the same condition.
     *
     * @return array{lines: list<array{id: int, title: string, moment: CarbonImmutable, days: int, checklist: ?string}>, total: int, ids: list<int>}
     */
    private function overdueList(User $viewer, User $employee, CarbonImmutable $now, int $total): array
    {
        $tasks = $this->tasks($viewer, $employee)
            ->open()
            ->where('tasks.due_at', '<', $now)
            ->orderBy('tasks.due_at')
            ->orderBy('tasks.id')
            ->limit(self::LIST_LIMIT)
            ->get(['tasks.id', 'tasks.title', 'tasks.due_at']);

        $lines = [];

        foreach ($tasks as $task) {
            $due = CarbonImmutable::instance($task->due_at ?? $now);
            $lines[] = $this->line($task, $due, self::days($due, $now), null);
        }

        return ['lines' => $lines, 'total' => $total, 'ids' => array_column($lines, 'id')];
    }

    /**
     * The employee's stalled tasks under the shared rule, quietest first.
     *
     * @return array{lines: list<array{id: int, title: string, moment: CarbonImmutable, days: int, checklist: ?string}>, total: int, ids: list<int>}
     */
    private function stalledList(User $viewer, User $employee, CarbonImmutable $now): array
    {
        $stalled = StalledTaskRule::constrain($this->tasks($viewer, $employee), $now);
        $total = (clone $stalled)->count();

        $tasks = StalledTaskRule::quietestFirst($stalled->select(['tasks.id', 'tasks.title', 'tasks.updated_at']))
            ->limit(self::LIST_LIMIT)
            ->get();

        $lines = [];

        foreach ($tasks as $task) {
            $since = StalledTaskRule::since($task, $now);
            $lines[] = $this->line($task, $since, self::days($since, $now), null);
        }

        return ['lines' => $lines, 'total' => $total, 'ids' => array_column($lines, 'id')];
    }

    /**
     * Tasks completed this month with at least one checklist item unticked,
     * newest completion first, with their "done/total" progress.
     *
     * @param  array{today: CarbonImmutable, tomorrow: CarbonImmutable, month: CarbonImmutable, next_month: CarbonImmutable, weeks: list<CarbonImmutable>, next_week: CarbonImmutable}  $calendar
     * @return array{lines: list<array{id: int, title: string, moment: CarbonImmutable, days: int, checklist: ?string}>, total: int, ids: list<int>}
     */
    private function checklistOpenList(User $viewer, User $employee, array $calendar): array
    {
        $query = $this->tasks($viewer, $employee)
            ->where('tasks.status', TaskStatus::Completed->value)
            ->where('tasks.completed_at', '>=', $calendar['month'])
            ->where('tasks.completed_at', '<', $calendar['next_month'])
            ->whereHas('checklistItems', static function (Builder $items): void {
                $items->whereNull('completed_at');
            });

        $total = (clone $query)->count();

        $tasks = TaskChecklist::withCounts($query->select(['tasks.id', 'tasks.title', 'tasks.completed_at']))
            ->orderByDesc('tasks.completed_at')
            ->orderByDesc('tasks.id')
            ->limit(self::LIST_LIMIT)
            ->get();

        $lines = [];

        foreach ($tasks as $task) {
            $lines[] = $this->line($task, CarbonImmutable::instance($task->completed_at ?? $calendar['month']), 0, TaskChecklist::progressLabel($task));
        }

        return ['lines' => $lines, 'total' => $total, 'ids' => array_column($lines, 'id')];
    }

    /**
     * The employee's newest progress entries and comments on tasks that are
     * not deleted, with the task's title.
     *
     * @return list<TaskUpdate>
     */
    private function latestEntries(User $employee): array
    {
        return array_values(TaskUpdate::query()
            ->where('user_id', $employee->getKey())
            ->whereHas('task')
            ->with('task:id,title')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::ACTIVITY_LIMIT)
            ->get()
            ->all());
    }

    /**
     * The listed tasks the reader may open (TaskPolicy::view), loaded once
     * with the linked records the policy may consult.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function readableTaskIds(User $viewer, array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $readable = [];

        foreach (Task::query()->with(['deal', 'lead', 'contact', 'account'])->whereKey($ids)->get() as $task) {
            if ($viewer->can('view', $task)) {
                $readable[] = (int) $task->getKey();
            }
        }

        return $readable;
    }

    /**
     * The tasks the employee holds — as the first assignee or as a
     * co-assignee of a shared task (D-28) — inside the reader's visibility
     * scope (deleted tasks are left out by the model's soft-delete scope).
     *
     * @return Builder<Task>
     */
    private function tasks(User $viewer, User $employee): Builder
    {
        return $this->visibility->visible($viewer, Task::query())
            ->assignedTo($employee);
    }

    /**
     * @return array{id: int, title: string, moment: CarbonImmutable, days: int, checklist: ?string}
     */
    private function line(Task $task, CarbonImmutable $moment, int $days, ?string $checklist): array
    {
        return [
            'id' => (int) $task->getKey(),
            'title' => (string) $task->title,
            'moment' => $moment->setTimezone($this->settings->timezone()),
            'days' => $days,
            'checklist' => $checklist,
        ];
    }

    /**
     * @param  list<array{id: int, title: string, moment: CarbonImmutable, days: int, checklist: ?string}>  $lines
     * @param  list<int>  $readable
     */
    private function list(array $lines, int $total, array $readable): EmployeeDashboardList
    {
        return new EmployeeDashboardList(
            array_map(static fn (array $line): EmployeeDashboardTask => new EmployeeDashboardTask(
                id: $line['id'],
                title: $line['title'],
                moment: $line['moment'],
                days: $line['days'],
                checklist: $line['checklist'],
                readable: in_array($line['id'], $readable, true),
            ), $lines),
            $total,
        );
    }

    /** Whole days from the moment to now, never negative. */
    private static function days(CarbonImmutable $since, CarbonImmutable $now): int
    {
        return (int) max(0, floor($since->diffInDays($now)));
    }
}
