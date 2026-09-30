<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Services\System\BackupSet;
use Carbon\CarbonInterface;
use Illuminate\Filesystem\Filesystem;

/**
 * Backup fixtures (decision D-16) shared by the backup, preflight and summary
 * tests: a private backup directory per test, and complete sets laid out
 * exactly as crm:backup leaves them. Never the real storage/app/backups.
 */
trait BuildsBackupSets
{
    /**
     * An empty directory under storage/framework/testing, configured as
     * crm.backup.path and removed after the test.
     */
    protected function useBackupDirectory(): string
    {
        $directory = $this->scratchBackupDirectory('backups');
        config()->set('crm.backup.path', $directory);

        return $directory;
    }

    /** A further empty scratch directory (a fake private disk, …), removed after the test. */
    protected function scratchBackupDirectory(string $name): string
    {
        $directory = storage_path('framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.$name.'-'.getmypid().'-'.bin2hex(random_bytes(4)));
        mkdir($directory, 0777, true);

        $this->beforeApplicationDestroyed(static function () use ($directory): void {
            (new Filesystem)->deleteDirectory($directory);
        });

        return $directory;
    }

    /**
     * A complete set taken at the given moment, with the given file contents.
     *
     * @return string the set id
     */
    protected function makeBackupSet(string $directory, CarbonInterface $takenAt, string $database = 'database dump', string $files = 'files archive'): string
    {
        $id = $takenAt->copy()->setTimezone((string) config('app.timezone'))->format(BackupSet::ID_FORMAT);
        $set = $directory.DIRECTORY_SEPARATOR.$id;

        mkdir($set, 0777, true);
        file_put_contents($set.DIRECTORY_SEPARATOR.BackupSet::FILE_NAMES[BackupSet::DATABASE], $database);
        file_put_contents($set.DIRECTORY_SEPARATOR.BackupSet::FILE_NAMES[BackupSet::FILES], $files);

        return $id;
    }
}
