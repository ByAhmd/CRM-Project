<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use Carbon\CarbonImmutable;

/**
 * The task part of the weekly summary (decision D-18), for the seven days
 * ending at `to`: both ends in the organisation timezone, which every other
 * moment of the report is in too.
 *
 * The overdue and stalled lists and the per-assignee table are capped
 * (WeeklySummary::LIST_LIMIT, ::ASSIGNEE_LIMIT); the totals count everything,
 * so the reader is told how many more there are.
 */
final readonly class WeeklySummaryReport
{
    /**
     * @param  list<WeeklySummaryTask>  $overdue  oldest due date first
     * @param  list<WeeklySummaryTask>  $stalled  quiet for longest first
     * @param  list<WeeklySummaryAssignee>  $assignees  most overdue first
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public int $completed,
        public int $handedOut,
        public int $overdueTotal,
        public array $overdue,
        public int $stalledTotal,
        public array $stalled,
        public int $assigneesTotal,
        public array $assignees,
    ) {}

    public function overdueMore(): int
    {
        return max(0, $this->overdueTotal - count($this->overdue));
    }

    public function stalledMore(): int
    {
        return max(0, $this->stalledTotal - count($this->stalled));
    }

    public function assigneesMore(): int
    {
        return max(0, $this->assigneesTotal - count($this->assignees));
    }

    /**
     * Every task the lists name.
     *
     * @return list<int>
     */
    public function taskIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (WeeklySummaryTask $task): int => $task->id,
            [...$this->overdue, ...$this->stalled],
        )));
    }
}
