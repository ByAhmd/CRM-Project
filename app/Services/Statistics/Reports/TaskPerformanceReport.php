<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Task performance (module 23, decision A-10): per assignee, the tasks
 * completed in the period (by completed_at), how many of them were on time
 * (completed no later than their due date, or without a due date) or late,
 * the tasks created in the period that are still open, the open tasks that
 * are overdue right now, the completion rate — completed over completed
 * plus still open — and the average time to finish (D-21).
 *
 * The time to finish of a task completed in the period runs from when it
 * reached its assignee (`assigned_at`, restamped on reassignment) to its
 * completion — from its creation when no assignment moment is recorded (an
 * unassigned task) — and is never negative: a task completed before it was
 * reassigned counts zero for its new assignee. Each row carries the summed
 * seconds (`finish_seconds`, no column) beside its completed count, so the
 * totals line is the mean over every completed task, not a mean of the
 * per-assignee means; a row without a completed task shows no average.
 * TIMESTAMPDIFF, GREATEST and COALESCE read the same on MySQL 8 and MariaDB
 * (A-21).
 *
 * Assignees are the viewer's visible task assignees (D-4, D-13) through
 * ReportFilters::scope() on the assignee column; only assignees with at
 * least one visible task appear, plus an "unassigned" line when unassigned
 * tasks are in reach. Cancelled tasks are neither completed nor open.
 *
 * "Overdue right now" reads the organisation clock (SettingsRepository),
 * never the application clock; ReportPagesTest guards that the two agree.
 */
final class TaskPerformanceReport
{
    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @return Collection<int, ReportRow>
     */
    public function rows(User $viewer, ReportFilters $filters): Collection
    {
        [$from, $to] = $filters->bounds();
        $completed = TaskStatus::Completed->value;
        $open = Task::openStatusValues();
        $openList = implode(', ', array_fill(0, count($open), '?'));
        $now = CarbonImmutable::now($this->settings->timezone())->format('Y-m-d H:i:s');

        $aggregates = [];

        foreach ($this->query($viewer, $filters)
            ->selectRaw('tasks.assignee_id AS assignee_id')
            ->selectRaw('SUM(CASE WHEN tasks.status = ? AND tasks.completed_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS completed', [$completed, $from, $to])
            ->selectRaw('SUM(CASE WHEN tasks.status = ? AND tasks.completed_at BETWEEN ? AND ? AND (tasks.due_at IS NULL OR tasks.completed_at <= tasks.due_at) THEN 1 ELSE 0 END) AS on_time', [$completed, $from, $to])
            ->selectRaw('SUM(CASE WHEN tasks.status = ? AND tasks.completed_at BETWEEN ? AND ? AND tasks.due_at IS NOT NULL AND tasks.completed_at > tasks.due_at THEN 1 ELSE 0 END) AS late', [$completed, $from, $to])
            ->selectRaw("SUM(CASE WHEN tasks.status IN ({$openList}) AND tasks.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS still_open", [...$open, $from, $to])
            ->selectRaw("SUM(CASE WHEN tasks.status IN ({$openList}) AND tasks.due_at < ? THEN 1 ELSE 0 END) AS overdue_now", [...$open, $now])
            ->selectRaw('SUM(CASE WHEN tasks.status = ? AND tasks.completed_at BETWEEN ? AND ? THEN GREATEST(0, TIMESTAMPDIFF(SECOND, COALESCE(tasks.assigned_at, tasks.created_at), tasks.completed_at)) ELSE 0 END) AS finish_seconds', [$completed, $from, $to])
            ->groupBy('tasks.assignee_id')
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

        foreach (User::query()->withTrashed()->whereIn('id', array_filter(array_keys($aggregates)))->orderBy('name')->get() as $assignee) {
            $rows->push(new ReportRow($assignee->name, $aggregates[(int) $assignee->getKey()], ['assignee_id' => (int) $assignee->getKey()]));
        }

        if (isset($aggregates[0])) {
            $rows->push(new ReportRow(__('reports.labels.unassigned'), $aggregates[0], ['assignee_id' => null]));
        }

        return $rows;
    }

    /**
     * Sums, with the completion rate recomputed and the time to finish
     * averaged over every completed task rather than every assignee.
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
     * @return Builder<Task>
     */
    private function query(User $viewer, ReportFilters $filters): Builder
    {
        return $filters->scope($viewer, $this->visibility, Task::query());
    }
}
