<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use App\Enums\TaskStatus;
use App\Enums\TaskUpdateKind;
use Carbon\CarbonImmutable;

/**
 * One line of the employee dashboard's latest activity (D-26): a progress
 * entry or a comment the employee wrote (D-14 amendment, D-17), on the
 * organisation clock. `status` is the status a progress entry moved the
 * task to (null for a plain note and for every comment). `readable` says
 * whether the reader may open the task; the page links the title only then
 * and shows plain text otherwise (A-20). `body` is the entry's text when
 * the task is readable and '' when it is not: the thread is the task's and
 * opens only with it (TaskPolicy::view).
 */
final readonly class EmployeeDashboardEntry
{
    public function __construct(
        public int $taskId,
        public string $taskTitle,
        public TaskUpdateKind $kind,
        public ?TaskStatus $status,
        public string $body,
        public CarbonImmutable $at,
        public bool $readable,
    ) {}
}
