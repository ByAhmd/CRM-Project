<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\System\BackupException;
use App\Services\System\BackupInProgressException;
use App\Services\System\BackupService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * *Back up now* on System → Backups (decision D-16): one backup run, picked
 * up by the scheduler's next queue drain (D-1), attributed to the super admin
 * who asked for it.
 *
 * Unlike every other job this one cannot be cut into batches that fit the
 * drain window: a run is one dump and one archive, and a large private disk
 * can take longer than the database queue's retry_after (90 s) and the
 * drain's own ten-minute overlap guard. A reserved row older than retry_after
 * is handed out again by the next drain, which with `tries = 1` would mark the
 * still-running backup failed, write a failed_jobs row and release the unique
 * lock early. The job therefore removes its queue row before it starts the
 * run: nothing is left for a later drain to pick up, the unique lock stays
 * held until the run ends (it is released after handle()), and BackupService's
 * own lock still refuses any second run. A run the host kills outright is
 * lost like a killed scheduled run — the next success cleans up after it, and
 * app:preflight and the weekly summary show the missing set.
 *
 * A failed run has already been reported and notified by BackupService, and
 * a run refused because another is under way is not a failure, so neither is
 * rethrown into failed_jobs.
 */
final class CreateBackup implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = BackupService::LOCK_SECONDS;

    public int $uniqueFor = BackupService::LOCK_SECONDS;

    public function __construct(
        public readonly ?int $requestedBy = null,
    ) {}

    public function handle(BackupService $backups): void
    {
        // Off the queue before the long run starts (see the class docblock).
        $this->delete();

        $causer = $this->requestedBy === null ? null : User::query()->find($this->requestedBy);

        try {
            $backups->run($causer);
        } catch (BackupInProgressException|BackupException) {
            // Reported and notified by BackupService, or another run is producing the set.
        }
    }
}
