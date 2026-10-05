<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use Carbon\CarbonImmutable;

/**
 * One task on a "needs attention" list of the employee dashboard (D-26).
 *
 * `moment` is what the list is about, on the organisation clock: the due
 * date of an overdue task, the last sign of progress on a stalled one, the
 * completion of a task finished with its checklist open. `days` counts the
 * whole days from that moment to now (overdue and stalled lists), and
 * `checklist` is the "done/total" progress (the checklist list). `readable`
 * says whether the reader may open the task (TaskPolicy::view); the page
 * links the title only then and shows plain text otherwise (A-20).
 */
final readonly class EmployeeDashboardTask
{
    public function __construct(
        public int $id,
        public string $title,
        public CarbonImmutable $moment,
        public int $days,
        public ?string $checklist,
        public bool $readable,
    ) {}
}
