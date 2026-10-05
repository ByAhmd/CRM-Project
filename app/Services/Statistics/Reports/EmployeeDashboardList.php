<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

/**
 * A capped list of the employee dashboard (D-26): at most
 * EmployeeDashboard::LIST_LIMIT tasks, and the total the list was cut from,
 * counted by its own query — so the page can say "+n more" truthfully.
 */
final readonly class EmployeeDashboardList
{
    /**
     * @param  list<EmployeeDashboardTask>  $tasks
     */
    public function __construct(
        public array $tasks,
        public int $total,
    ) {}

    public function isEmpty(): bool
    {
        return $this->tasks === [];
    }

    /** How many tasks the list leaves out. */
    public function more(): int
    {
        return max(0, $this->total - count($this->tasks));
    }
}
