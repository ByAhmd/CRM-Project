<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Notifications\WeeklySummaryNotification;
use App\Services\Settings\SettingsRepository;
use App\Services\System\BackupService;
use App\Services\System\LoggedErrorCounter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The weekly summary e-mail to the people who hand out work (decision D-18).
 *
 * report() is a read model of the seven days ending at the given moment:
 * tasks completed in the window (in total and per assignee), open tasks
 * overdue now (oldest due first), stalled tasks (in progress with no
 * progress entry or comment for STALLED_AFTER_DAYS), tasks handed out in the
 * window (created with an assignee other than the person who assigned them),
 * and a per-assignee table (open / overdue / completed). Task titles and
 * assignees are organisation-wide, as on the shared board (D-14). Every
 * figure comes from a fixed number of queries whatever the data size — one
 * grouped aggregate, one name lookup, and a count plus a capped list for
 * each of overdue and stalled — and every moment is converted to the
 * organisation timezone in PHP (A-19), never with SQL date functions.
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

    /** An in-progress task with no progress entry or comment for this long is stalled. */
    public const int STALLED_AFTER_DAYS = 3;

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

        $stalled = $this->stalledQuery($to);

        return new WeeklySummaryReport(
            from: $from->setTimezone($timezone),
            to: $to->setTimezone($timezone),
            completed: array_sum(array_column($rows, 'completed')),
            handedOut: $this->handedOutCount($from, $to),
            overdueTotal: array_sum(array_column($rows, 'overdue')),
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
     * One grouped aggregate over the open tasks and those completed in the
     * window, sorted most overdue first, then most open, most completed and
     * name; and the names of every assignee in it (deleted accounts included).
     *
     * @return array{0: list<array{assignee_id: ?int, open: int, overdue: int, completed: int}>, 1: array<int, string>}
     */
    private function perAssignee(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $open = Task::openStatusValues();
        $marks = implode(', ', array_fill(0, count($open), '?'));

        $aggregates = Task::query()
            ->where(static function (Builder $query) use ($open, $from, $to): void {
                $query->whereIn('status', $open)
                    ->orWhere(static function (Builder $done) use ($from, $to): void {
                        $done->where('status', TaskStatus::Completed->value)
                            ->whereBetween('completed_at', [$from, $to]);
                    });
            })
            ->select('assignee_id')
            ->selectRaw("SUM(CASE WHEN status IN ({$marks}) THEN 1 ELSE 0 END) AS open_count", $open)
            ->selectRaw("SUM(CASE WHEN status IN ({$marks}) AND due_at < ? THEN 1 ELSE 0 END) AS overdue_count", [...$open, $to])
            ->selectRaw('SUM(CASE WHEN status = ? AND completed_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS completed_count', [TaskStatus::Completed->value, $from, $to])
            ->groupBy('assignee_id')
            ->toBase()
            ->get();

        $rows = [];

        foreach ($aggregates as $aggregate) {
            $rows[] = [
                'assignee_id' => $aggregate->assignee_id === null ? null : (int) $aggregate->assignee_id,
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

    private function handedOutCount(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Task::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('assignee_id')
            ->whereNotNull('assigned_by')
            ->whereColumn('assigned_by', '<>', 'assignee_id')
            ->count();
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
     * In progress, no thread entry since the cutoff, and — for a task from
     * before the thread existed, which has no entry at all — not touched
     * since the cutoff either.
     *
     * @return Builder<Task>
     */
    private function stalledQuery(CarbonImmutable $to): Builder
    {
        $cutoff = $to->subDays(self::STALLED_AFTER_DAYS);

        return Task::query()
            ->where('status', TaskStatus::InProgress->value)
            ->whereDoesntHave('updates', static function (Builder $entries) use ($cutoff): void {
                $entries->where('created_at', '>=', $cutoff);
            })
            ->where(static function (Builder $query) use ($cutoff): void {
                $query->has('updates')->orWhere('updated_at', '<', $cutoff);
            });
    }

    /**
     * @param  Builder<Task>  $query
     * @param  array<int, string>  $names
     * @return list<WeeklySummaryTask>
     */
    private function stalledList(Builder $query, CarbonImmutable $to, array $names, string $timezone): array
    {
        $tasks = $query
            ->select(['id', 'title', 'assignee_id', 'updated_at'])
            ->addSelect([
                'last_entry_at' => TaskUpdate::query()
                    ->select('created_at')
                    ->whereColumn('task_updates.task_id', 'tasks.id')
                    ->orderByDesc('created_at')
                    ->limit(1),
            ])
            ->orderBy('last_entry_at')
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        $lines = [];

        foreach ($tasks as $task) {
            $entry = $task->getAttribute('last_entry_at');
            $since = is_string($entry) && $entry !== ''
                ? CarbonImmutable::parse($entry, (string) config('app.timezone'))
                : CarbonImmutable::instance($task->updated_at ?? $to);

            $lines[] = $this->line($task, $names, $since, $to, $timezone);
        }

        return $lines;
    }

    /**
     * @param  array<int, string>  $names
     */
    private function line(Task $task, array $names, CarbonImmutable $since, CarbonImmutable $to, string $timezone): WeeklySummaryTask
    {
        $assignee = $task->assignee_id === null ? null : ($names[(int) $task->assignee_id] ?? null);

        return new WeeklySummaryTask(
            id: (int) $task->getKey(),
            title: (string) $task->title,
            assigneeName: $assignee,
            since: $since->setTimezone($timezone),
            days: (int) max(0, floor($since->diffInDays($to))),
        );
    }
}
