<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Models\Task;
use App\Models\TaskCoAssignee;
use App\Models\User;
use App\Notifications\WeeklySummaryNotification;
use App\Services\Settings\SettingsRepository;
use App\Services\System\BackupService;
use App\Services\System\LoggedErrorCounter;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The weekly summary e-mail to the people who hand out work (decision D-18).
 *
 * report() is a read model of the seven days ending at the given moment:
 * tasks completed in the window (in total and per assignee), open tasks
 * overdue now (oldest due first), stalled tasks (in progress with no
 * progress entry, comment or checklist tick — D-25 — for
 * STALLED_AFTER_DAYS, under the rule StalledTaskRule shares with the
 * employee dashboard, D-26), tasks handed out in the
 * window (created with an assignee other than the person who assigned them),
 * and a per-assignee table (open / overdue / completed). Task titles and
 * assignees are organisation-wide, as on the shared board (D-14). Every
 * figure comes from a fixed number of queries whatever the data size — one
 * grouped aggregate per assignee, one of the task totals, one name lookup,
 * two counts of what was handed out, and a count plus a capped list (with
 * its co-assignees) for each of overdue and stalled — and every moment is
 * converted to the organisation timezone in PHP (A-19), never with SQL date
 * functions.
 *
 * A shared task (D-28) is the work of each of its assignees: the
 * per-assignee table counts it in every holder's row (open, overdue,
 * completed), and the «unassigned» row keeps only the tasks nobody holds.
 * The totals still count tasks — a shared task completed once is one
 * completion — so for an unshared task every figure means what it always
 * did. Adding a colleague to a task is handing it out to them: a co-assignee
 * added in the window by someone else counts as handed out, beside the tasks
 * created for someone else. A listed task names its first assignee and how
 * many others hold it.
 *
 * health() is the super admins' part: the newest backup set or the failure
 * recorded since, failed queue jobs and ERROR-or-higher log entries in the
 * window (counted only; log contents never leave the server).
 *
 * send() delivers one WeeklySummaryNotification per active user who holds
 * `task.assign`, in their own locale, with the health part only for holders
 * of `roles.manage`, and links only to the listed tasks the recipient's
 * TaskPolicy lets them open. A failed delivery is reported and does not stop
 * the others.
 */
final class WeeklySummary
{
    public const int WINDOW_DAYS = 7;

    /** An in-progress task with no progress entry, comment or checklist tick for this long is stalled (StalledTaskRule). */
    public const int STALLED_AFTER_DAYS = StalledTaskRule::AFTER_DAYS;

    /** The most tasks the overdue and the stalled list each name. */
    public const int LIST_LIMIT = 10;

    /** The most rows of the per-assignee table. */
    public const int ASSIGNEE_LIMIT = 20;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly BackupService $backups,
        private readonly LoggedErrorCounter $errors,
    ) {}

    /**
     * Sends the summary to every recipient and returns how many were sent.
     */
    public function send(CarbonImmutable $now): int
    {
        $recipients = $this->recipients();

        if ($recipients->isEmpty()) {
            return 0;
        }

        $report = $this->report($now);

        $healthReaders = array_map('intval', User::query()
            ->permission(Permission::RolesManage->value)
            ->whereKey($recipients->modelKeys())
            ->pluck('id')
            ->all());

        $health = $healthReaders === [] ? null : $this->health($now);

        // Loaded once with the records the policy may consult (a task is also
        // readable through its linked record), then checked per recipient.
        $tasks = $report->taskIds() === []
            ? new Collection
            : Task::query()->with(['deal', 'lead', 'contact', 'account'])->whereKey($report->taskIds())->get();

        $sent = 0;

        foreach ($recipients as $recipient) {
            $readable = [];

            foreach ($tasks as $task) {
                if ($recipient->can('view', $task)) {
                    $readable[] = (int) $task->getKey();
                }
            }

            $notification = new WeeklySummaryNotification(
                $report,
                in_array((int) $recipient->getKey(), $healthReaders, true) ? $health : null,
                $readable,
            );

            try {
                $recipient->notify($notification->locale($recipient->preferredLocale()));
                $sent++;
            } catch (Throwable $exception) {
                // The bell entry is written before mail is attempted; one
                // broken mailbox must not keep the summary from the others.
                report($exception);
            }
        }

        return $sent;
    }

    /**
     * Active users who hold `task.assign`, through a role or directly.
     *
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->permission(Permission::TaskAssign->value)
            ->with(['roles', 'permissions'])
            ->orderBy('id')
            ->get();
    }

    public function report(CarbonImmutable $now): WeeklySummaryReport
    {
        [$from, $to] = $this->window($now);
        $timezone = $this->settings->timezone();

        [$rows, $names] = $this->perAssignee($from, $to);
        $totals = $this->totals($from, $to);

        $stalled = $this->stalledQuery($to);

        return new WeeklySummaryReport(
            from: $from->setTimezone($timezone),
            to: $to->setTimezone($timezone),
            completed: $totals['completed'],
            handedOut: $this->handedOutCount($from, $to),
            overdueTotal: $totals['overdue'],
            overdue: $this->overdueList($to, $names, $timezone),
            stalledTotal: (clone $stalled)->count(),
            stalled: $this->stalledList($stalled, $to, $names, $timezone),
            assigneesTotal: count($rows),
            assignees: array_map(
                static fn (array $row): WeeklySummaryAssignee => new WeeklySummaryAssignee(
                    $row['assignee_id'] === null ? null : ($names[$row['assignee_id']] ?? null),
                    $row['open'],
                    $row['overdue'],
                    $row['completed'],
                ),
                array_slice($rows, 0, self::ASSIGNEE_LIMIT),
            ),
        );
    }

    public function health(CarbonImmutable $now): WeeklySummaryHealth
    {
        [$from, $to] = $this->window($now);
        $logged = $this->errors->count($from, $to);
        $connection = config('queue.failed.database');

        return new WeeklySummaryHealth(
            latestBackup: $this->backups->latest(),
            backupFailure: $this->backups->lastFailure(),
            failedJobs: DB::connection(is_string($connection) && $connection !== '' ? $connection : null)
                ->table((string) config('queue.failed.table', 'failed_jobs'))
                ->whereBetween('failed_at', [$from, $to])
                ->count(),
            loggedErrors: $logged['errors'],
            errorsComplete: $logged['complete'],
        );
    }

    /**
     * The window in the application timezone the datetime columns are stored in.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(CarbonImmutable $now): array
    {
        $to = $now->setTimezone((string) config('app.timezone'));

        return [$to->subDays(self::WINDOW_DAYS), $to];
    }

    /**
     * One grouped aggregate over the assignments of the open tasks and of
     * those completed in the window (assignments(): a shared task counts for
     * each of its holders, D-28), sorted most overdue first, then most open,
     * most completed and name; and the names of every assignee in it
     * (deleted accounts included).
     *
     * @return array{0: list<array{assignee_id: ?int, open: int, overdue: int, completed: int}>, 1: array<int, string>}
     */
    private function perAssignee(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $open = Task::openStatusValues();
        $marks = implode(', ', array_fill(0, count($open), '?'));

        $aggregates = DB::query()
            ->fromSub($this->assignments($from, $to), 'assignments')
            ->select('user_id')
            ->selectRaw("SUM(CASE WHEN `status` IN ({$marks}) THEN 1 ELSE 0 END) AS open_count", $open)
            ->selectRaw("SUM(CASE WHEN `status` IN ({$marks}) AND `due_at` < ? THEN 1 ELSE 0 END) AS overdue_count", [...$open, $to])
            ->selectRaw('SUM(CASE WHEN `status` = ? AND `completed_at` BETWEEN ? AND ? THEN 1 ELSE 0 END) AS completed_count', [TaskStatus::Completed->value, $from, $to])
            ->groupBy('user_id')
            ->get();

        $rows = [];

        foreach ($aggregates as $aggregate) {
            $rows[] = [
                'assignee_id' => $aggregate->user_id === null ? null : (int) $aggregate->user_id,
                'open' => (int) $aggregate->open_count,
                'overdue' => (int) $aggregate->overdue_count,
                'completed' => (int) $aggregate->completed_count,
            ];
        }

        $ids = array_values(array_filter(array_column($rows, 'assignee_id'), static fn (?int $id): bool => $id !== null));

        /** @var array<int, string> $names */
        $names = $ids === []
            ? []
            : User::withTrashed()->whereKey($ids)->pluck('name', 'id')->all();

        usort($rows, static function (array $a, array $b) use ($names): int {
            return [$b['overdue'], $b['open'], $b['completed'], $a['assignee_id'] === null ? 1 : 0, $names[$a['assignee_id'] ?? 0] ?? '']
                <=> [$a['overdue'], $a['open'], $a['completed'], $b['assignee_id'] === null ? 1 : 0, $names[$b['assignee_id'] ?? 0] ?? ''];
        });

        return [$rows, $names];
    }

    /**
     * The task totals of the week, each task once however many hold it
     * (D-28): the tasks completed in the window and the open tasks overdue
     * now — one aggregate over the same tasks as the per-assignee table.
     *
     * @return array{completed: int, overdue: int}
     */
    private function totals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $open = Task::openStatusValues();
        $marks = implode(', ', array_fill(0, count($open), '?'));

        $row = DB::table('tasks')
            ->where(self::inWindow($from, $to))
            ->selectRaw("SUM(CASE WHEN `tasks`.`status` IN ({$marks}) AND `tasks`.`due_at` < ? THEN 1 ELSE 0 END) AS overdue_count", [...$open, $to])
            ->selectRaw('SUM(CASE WHEN `tasks`.`status` = ? AND `tasks`.`completed_at` BETWEEN ? AND ? THEN 1 ELSE 0 END) AS completed_count', [TaskStatus::Completed->value, $from, $to])
            ->first();

        return [
            'completed' => (int) ($row->completed_count ?? 0),
            'overdue' => (int) ($row->overdue_count ?? 0),
        ];
    }

    /**
     * What the summary reads — the open tasks and those completed in the
     * window, never a deleted one — as assignments (D-28): one row per task
     * and holder with the task's `status`, `due_at` and `completed_at` and the
     * holder's `user_id`, for the first assignee and for each co-assignee, and
     * one row with a null `user_id` for a task nobody holds at all.
     */
    private function assignments(CarbonImmutable $from, CarbonImmutable $to): QueryBuilder
    {
        $columns = '`tasks`.`status` AS `status`, `tasks`.`due_at` AS `due_at`, `tasks`.`completed_at` AS `completed_at`';

        $first = DB::table('tasks')
            ->where(self::inWindow($from, $to))
            // A task without a first assignee is «unassigned» only when no
            // co-assignee holds it either.
            ->where(static function (QueryBuilder $held): void {
                $held->whereNotNull('tasks.assignee_id')
                    ->orWhereNotExists(static function (QueryBuilder $coAssigned): void {
                        $coAssigned->selectRaw('1')
                            ->from('task_co_assignees')
                            ->whereColumn('task_co_assignees.task_id', 'tasks.id');
                    });
            })
            ->selectRaw('`tasks`.`assignee_id` AS `user_id`, '.$columns);

        $coAssigned = DB::table('task_co_assignees')
            ->join('tasks', 'tasks.id', '=', 'task_co_assignees.task_id')
            ->where(self::inWindow($from, $to))
            ->selectRaw('`task_co_assignees`.`user_id` AS `user_id`, '.$columns);

        return $first->unionAll($coAssigned);
    }

    /**
     * The tasks the summary's figures are about: not deleted, and open or
     * completed inside the window — as a nested constraint on `tasks`.
     *
     * @return Closure(QueryBuilder): void
     */
    private static function inWindow(CarbonImmutable $from, CarbonImmutable $to): Closure
    {
        return static function (QueryBuilder $query) use ($from, $to): void {
            $query->whereNull('tasks.deleted_at')
                ->where(static function (QueryBuilder $tasks) use ($from, $to): void {
                    $tasks->whereIn('tasks.status', Task::openStatusValues())
                        ->orWhere(static function (QueryBuilder $done) use ($from, $to): void {
                            $done->where('tasks.status', TaskStatus::Completed->value)
                                ->whereBetween('tasks.completed_at', [$from, $to]);
                        });
                });
        };
    }

    /**
     * What was handed out in the window: the tasks created for someone other
     * than the person who assigned them, and — D-28 — every colleague added
     * to a task by someone else, each a hand-out of its own. Deleted tasks
     * count for neither.
     */
    private function handedOutCount(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $created = Task::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('assignee_id')
            ->whereNotNull('assigned_by')
            ->whereColumn('assigned_by', '<>', 'assignee_id')
            ->count();

        $shared = TaskCoAssignee::query()
            ->whereBetween('assigned_at', [$from, $to])
            ->whereNotNull('assigned_by')
            ->whereColumn('assigned_by', '<>', 'user_id')
            ->whereIn('task_id', Task::query()->select('tasks.id'))
            ->count();

        return $created + $shared;
    }

    /**
     * @param  array<int, string>  $names
     * @return list<WeeklySummaryTask>
     */
    private function overdueList(CarbonImmutable $to, array $names, string $timezone): array
    {
        $tasks = Task::query()
            ->open()
            ->where('due_at', '<', $to)
            ->with('coAssignees')
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'title', 'assignee_id', 'due_at']);

        $lines = [];

        foreach ($tasks as $task) {
            $due = CarbonImmutable::instance($task->due_at ?? $to);

            $lines[] = $this->line($task, $names, $due, $to, $timezone);
        }

        return $lines;
    }

    /**
     * The organisation's stalled tasks under the one shared rule
     * (StalledTaskRule, D-18 / D-25 / D-26).
     *
     * @return Builder<Task>
     */
    private function stalledQuery(CarbonImmutable $to): Builder
    {
        return StalledTaskRule::constrain(Task::query(), $to);
    }

    /**
     * @param  Builder<Task>  $query
     * @param  array<int, string>  $names
     * @return list<WeeklySummaryTask>
     */
    private function stalledList(Builder $query, CarbonImmutable $to, array $names, string $timezone): array
    {
        $tasks = StalledTaskRule::quietestFirst($query->select(['id', 'title', 'assignee_id', 'updated_at']))
            ->with('coAssignees')
            ->limit(self::LIST_LIMIT)
            ->get();

        $lines = [];

        foreach ($tasks as $task) {
            $lines[] = $this->line($task, $names, StalledTaskRule::since($task, $to), $to, $timezone);
        }

        return $lines;
    }

    /**
     * One listed task, naming its first assignee — from the names the
     * per-assignee table read, deleted accounts included — and how many
     * others hold it (D-28). A shared task whose first assignee left it is
     * named after its earliest co-assignee.
     *
     * @param  array<int, string>  $names
     */
    private function line(Task $task, array $names, CarbonImmutable $since, CarbonImmutable $to, string $timezone): WeeklySummaryTask
    {
        $firstId = $task->assignee_id === null ? null : (int) $task->assignee_id;
        $others = $task->coAssignees
            ->reject(static fn (User $user): bool => (int) $user->getKey() === $firstId)
            ->values();

        if ($firstId !== null) {
            $assignee = $names[$firstId] ?? null;
        } else {
            $assignee = $others->first()?->name;
            $others = $others->slice(1);
        }

        return new WeeklySummaryTask(
            id: (int) $task->getKey(),
            title: (string) $task->title,
            assigneeName: $assignee,
            since: $since->setTimezone($timezone),
            days: (int) max(0, floor($since->diffInDays($to))),
            otherAssignees: $assignee === null ? 0 : $others->count(),
        );
    }
}
