<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use Carbon\CarbonImmutable;

/**
 * One line of an overdue or stalled list in the weekly summary (decision
 * D-18): the task, its assignee's name (null when nobody is assigned), and
 * the moment the list is about — the due date of an overdue task, the last
 * progress entry or comment of a stalled one — in the organisation timezone,
 * with the whole days elapsed since then.
 */
final readonly class WeeklySummaryTask
{
    public function __construct(
        public int $id,
        public string $title,
        public ?string $assigneeName,
        public CarbonImmutable $since,
        public int $days,
    ) {}
}
