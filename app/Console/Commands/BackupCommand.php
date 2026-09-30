<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\BackupException;
use App\Services\System\BackupInProgressException;
use App\Services\System\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

/**
 * `crm:backup` — one backup set now (decision D-16). Scheduled every Thursday
 * at 22:00 (routes/console.php); safe to run by hand at any time. All the
 * work, the failure notice to super admins included, is BackupService's; this
 * command prints the outcome for the operator (plain English, like
 * app:preflight) and exits non-zero when no set was written.
 */
final class BackupCommand extends Command
{
    protected $signature = 'crm:backup';

    protected $description = 'Back up the database and the private storage disk into a new set and prune old sets (D-16)';

    public function handle(BackupService $backups): int
    {
        try {
            $set = $backups->run();
        } catch (BackupInProgressException $exception) {
            $this->error('[backup] '.$exception->getMessage());

            return self::FAILURE;
        } catch (BackupException $exception) {
            $this->error('[backup] FAILED ('.$exception->reason.'): '.$exception->getMessage());

            return self::FAILURE;
        }

        $kept = count($backups->sets());

        $this->info(sprintf(
            '[backup] Set %s written to %s: database %s, files %s. %d set%s kept.',
            $set->id,
            $set->directory,
            Number::fileSize($set->databaseBytes, maxPrecision: 1),
            Number::fileSize($set->filesBytes, maxPrecision: 1),
            $kept,
            $kept === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }
}
