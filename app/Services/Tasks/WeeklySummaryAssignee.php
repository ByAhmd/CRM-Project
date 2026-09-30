<?php

declare(strict_types=1);

namespace App\Services\Tasks;

/**
 * One row of the weekly summary's per-assignee table (decision D-18): open
 * tasks now, how many of them are overdue, and tasks completed in the week.
 * `name` is null for the tasks nobody is assigned to.
 */
final readonly class WeeklySummaryAssignee
{
    public function __construct(
        public ?string $name,
        public int $open,
        public int $overdue,
        public int $completed,
    ) {}
}
