<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use Carbon\CarbonImmutable;

/**
 * Everything the employee dashboard shows about one employee at one
 * moment (D-26), as EmployeeDashboard computed it.
 *
 * `onTimeRate` (a percentage with one decimal) and `averageSecondsToFinish`
 * are null when nothing was completed this month — the page shows «—»,
 * never 0%. `trend` holds the last TREND_WEEKS organisation weeks, oldest
 * first, the current week last; `completedThisWeek` is that last bucket.
 */
final readonly class EmployeeDashboardSnapshot
{
    /**
     * @param  list<array{start: CarbonImmutable, completed: int}>  $trend
     * @param  list<EmployeeDashboardEntry>  $latestActivity
     */
    public function __construct(
        public int $open,
        public int $overdue,
        public int $dueToday,
        public int $completedThisWeek,
        public int $completedThisMonth,
        public ?float $onTimeRate,
        public ?int $averageSecondsToFinish,
        public array $trend,
        public EmployeeDashboardList $overdueTasks,
        public EmployeeDashboardList $stalledTasks,
        public EmployeeDashboardList $checklistOpenTasks,
        public array $latestActivity,
    ) {}
}
