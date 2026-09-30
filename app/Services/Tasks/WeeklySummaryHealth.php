<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Services\System\BackupFailure;
use App\Services\System\BackupSet;

/**
 * The system health part of the weekly summary, for super admins only
 * (decision D-18): the newest complete backup set and the failed run
 * recorded since (BackupService clears it on the next success), failed queue
 * jobs and ERROR-or-higher log entries in the window. `errorsComplete` is
 * false when the log was larger than LoggedErrorCounter reads, so the count
 * is a lower bound. No log content is ever carried.
 */
final readonly class WeeklySummaryHealth
{
    public function __construct(
        public ?BackupSet $latestBackup,
        public ?BackupFailure $backupFailure,
        public int $failedJobs,
        public int $loggedErrors,
        public bool $errorsComplete,
    ) {}
}
