<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Jobs\CreateBackup;
use App\Models\ActivityLog;
use App\Notifications\BackupFailedNotification;
use App\Services\System\BackupException;
use App\Services\System\BackupService;
use App\Services\System\BackupSet;
use App\Support\System\Paths;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsBackupSets;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The CRM's own backup (decision D-16): crm:backup writes one complete set —
 * gzip'd mysqldump plus a tar.gz of the private disk — or nothing, reports and
 * notifies every failure, keeps the newest sets, never touches anything else,
 * and hands mysqldump its credentials only through a private defaults file.
 *
 * mysqldump and tar are faked (Process::fake); the fakes write the files the
 * real tools would, into a per-test backup directory and a per-test private
 * disk under storage/framework/testing.
 */
final class BackupTest extends TestCase
{
    use BuildsBackupSets;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const string DUMP = "-- MySQL dump\nCREATE TABLE `leads` (`id` bigint);\n";

    private string $backups;

    private string $private;

    /** @var list<array{command: list<string>, defaults: string|null, permissions: string|null}> */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();

        $this->backups = $this->useBackupDirectory();
        $this->private = $this->scratchBackupDirectory('private-disk');
        mkdir($this->private.DIRECTORY_SEPARATOR.'crm', 0777, true);
        file_put_contents($this->private.DIRECTORY_SEPARATOR.'crm'.DIRECTORY_SEPARATOR.'contract.pdf', 'pdf bytes');
        config()->set('filesystems.disks.local.root', $this->private);
        config()->set('crm.backup.keep', 8);
        config()->set('crm.backup.mysqldump', 'mysqldump');

        $this->travelTo(CarbonImmutable::parse('2026-10-01 22:00:00', 'Asia/Riyadh'));
    }

    #[Test]
    public function a_run_writes_one_complete_set_of_the_gzipped_dump_and_the_private_disk_archive(): void
    {
        $this->fakeTools();

        $this->artisan('crm:backup')
            ->expectsOutputToContain('[backup] Set 20261001-220000 written to')
            ->assertSuccessful();

        $set = $this->backups.DIRECTORY_SEPARATOR.'20261001-220000';

        $this->assertSame(self::DUMP, gzdecode((string) file_get_contents($set.DIRECTORY_SEPARATOR.'database.sql.gz')));
        $this->assertSame('tar of '.$this->private, file_get_contents($set.DIRECTORY_SEPARATOR.'files.tar.gz'));
        $this->assertSame(['database.sql.gz', 'files.tar.gz'], $this->entries($set), 'the uncompressed dump is removed');
        $this->assertSame(['20261001-220000'], $this->entries($this->backups), 'no partial folder or failure record is left');

        $latest = app(BackupService::class)->latest();
        $this->assertNotNull($latest);
        $this->assertSame('20261001-220000', $latest->id);
        $this->assertTrue($latest->createdAt->equalTo(now()));
        $this->assertSame(strlen('tar of '.$this->private), $latest->filesBytes);
        $this->assertNull(app(BackupService::class)->lastFailure());

        [$dump, $tar] = $this->runs;
        $database = (string) config('database.connections.'.config('database.default').'.database');

        $this->assertSame('mysqldump', $dump['command'][0]);
        $this->assertStringStartsWith('--defaults-extra-file=', $dump['command'][1], 'the defaults file must be the first option');
        $this->assertContains('--single-transaction', $dump['command']);
        $this->assertContains('--routines', $dump['command']);
        $this->assertContains('--triggers', $dump['command']);
        $this->assertContains('--no-tablespaces', $dump['command']);
        $this->assertContains('--loose-set-gtid-purged=OFF', $dump['command'], 'no FLUSH TABLES (RELOAD privilege) on a GTID server');
        $this->assertSame($database, $dump['command'][array_key_last($dump['command'])]);
        $this->assertSame(['tar', '-czf'], array_slice($tar['command'], 0, 2));
        $this->assertSame(['-C', $this->private, '.'], array_slice($tar['command'], 3));

        $log = ActivityLog::query()->where('description', ActivityLogEvent::BackupCreated->value)->sole();
        $this->assertSame('20261001-220000', $log->properties->get('subject_label'));
        $this->assertNull($log->causer_id, 'the scheduled run has no causer');
    }

    #[Test]
    public function credentials_reach_mysqldump_only_through_a_private_defaults_file_removed_after_the_dump(): void
    {
        $connection = 'database.connections.'.config('database.default');
        config()->set($connection.'.username', 'crm_user');
        config()->set($connection.'.password', 'Se"cr\\et#pw 1');
        config()->set($connection.'.host', 'db.internal');
        config()->set($connection.'.port', '3310');
        config()->set($connection.'.unix_socket', '');
        $this->fakeTools();

        $this->artisan('crm:backup')->assertSuccessful();

        $dump = $this->runs[0];
        $defaultsFile = substr($dump['command'][1], strlen('--defaults-extra-file='));

        $this->assertSame(
            "[client]\nuser=\"crm_user\"\npassword=\"Se\\\"cr\\\\et#pw 1\"\nhost=\"db.internal\"\nport=\"3310\"\n",
            $dump['defaults'],
        );
        $this->assertSame('Se"cr\\et#pw 1', $this->optionFileValue((string) $dump['defaults'], 'password'), 'the client reads the password back unchanged');
        $this->assertFileDoesNotExist($defaultsFile);

        foreach ($dump['command'] as $argument) {
            $this->assertStringNotContainsString('cr\\et', $argument, 'the password never appears on the command line');
            $this->assertStringNotContainsString('crm_user', $argument, 'nor does the user');
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame('0600', $dump['permissions']);
        }
    }

    #[Test]
    public function a_socket_connection_is_passed_as_socket_instead_of_host_and_port(): void
    {
        config()->set('database.connections.'.config('database.default').'.unix_socket', '/var/run/mysqld/mysqld.sock');
        $this->fakeTools();

        $this->artisan('crm:backup')->assertSuccessful();

        $this->assertStringContainsString("socket=\"/var/run/mysqld/mysqld.sock\"\n", (string) $this->runs[0]['defaults']);
        $this->assertStringNotContainsString('host=', (string) $this->runs[0]['defaults']);
    }

    #[Test]
    public function a_failing_dump_leaves_no_set_reports_records_notifies_active_super_admins_and_exits_non_zero(): void
    {
        Notification::fake();
        Exceptions::fake();
        $superAdmin = $this->superAdmin();
        $disabled = $this->makeUser(CrmRole::SuperAdmin, ['name' => 'Former Super Admin', 'status' => UserStatus::Disabled->value]);
        $admin = $this->admin();
        // A custom role granted roles.manage sees System → Backups, so it hears about failures too.
        $rolesManager = $this->userWithPermissions(null, [Permission::RolesManage]);
        $this->fakeTools(dumpExit: 2, dumpError: 'mysqldump: Got error: 1045: Access denied for user');

        $this->artisan('crm:backup')
            ->expectsOutputToContain('[backup] FAILED (database): mysqldump exited with code 2: mysqldump: Got error: 1045: Access denied for user')
            ->assertFailed();

        $this->assertSame(['last-failure.json'], $this->entries($this->backups), 'the partial set is removed');
        $this->assertSame([], app(BackupService::class)->sets());
        $this->assertCount(1, $this->runs, 'tar never runs after a failed dump');

        $failure = app(BackupService::class)->lastFailure();
        $this->assertNotNull($failure);
        $this->assertSame(BackupException::REASON_DATABASE, $failure->reason);
        $this->assertStringContainsString('Access denied', $failure->detail);
        $this->assertTrue($failure->occurredAt->equalTo(now()));

        Exceptions::assertReported(fn (BackupException $exception): bool => $exception->reason === BackupException::REASON_DATABASE);
        Notification::assertSentTo($superAdmin, BackupFailedNotification::class);
        Notification::assertSentTo($rolesManager, BackupFailedNotification::class);
        Notification::assertNotSentTo($disabled, BackupFailedNotification::class);
        Notification::assertNotSentTo($admin, BackupFailedNotification::class);

        $this->assertSame('20261001-220000', ActivityLog::query()->where('description', ActivityLogEvent::BackupFailed->value)->sole()->properties->get('subject_label'));
    }

    #[Test]
    public function a_failing_archive_removes_the_already_written_dump_with_its_partial_set(): void
    {
        Notification::fake();
        Exceptions::fake();
        $this->fakeTools(tarExit: 2, tarError: 'tar: Cannot open: Permission denied');

        $this->artisan('crm:backup')
            ->expectsOutputToContain('[backup] FAILED (files): tar exited with code 2')
            ->assertFailed();

        $this->assertSame(['last-failure.json'], $this->entries($this->backups));
        $this->assertSame(BackupException::REASON_FILES, app(BackupService::class)->lastFailure()?->reason);
    }

    #[Test]
    public function a_tool_that_exits_zero_without_writing_its_file_is_a_failure(): void
    {
        Notification::fake();
        Exceptions::fake();
        $this->fakeTools(tarWrites: false);

        $this->artisan('crm:backup')
            ->expectsOutputToContain('[backup] FAILED (incomplete): files.tar.gz is missing or empty after the run.')
            ->assertFailed();

        $this->assertSame([], app(BackupService::class)->sets());

        $this->fakeTools(dumpWrites: false);

        $this->artisan('crm:backup')
            ->expectsOutputToContain('[backup] FAILED (database): mysqldump reported success but wrote no dump.')
            ->assertFailed();
    }

    #[Test]
    public function a_backup_directory_under_public_or_inside_a_storage_disk_is_refused_and_never_created(): void
    {
        Notification::fake();
        Exceptions::fake();
        $this->fakeTools();

        foreach ([public_path('crm-backups-test'), $this->private.DIRECTORY_SEPARATOR.'backups', storage_path('app/public/backups-test')] as $unsafe) {
            config()->set('crm.backup.path', $unsafe);

            $this->artisan('crm:backup')
                ->expectsOutputToContain('[backup] FAILED (location): The backup directory')
                ->assertFailed();

            $this->assertDirectoryDoesNotExist($unsafe);
        }

        $this->assertSame([], $this->runs, 'no tool ran');
        Exceptions::assertReported(fn (BackupException $exception): bool => $exception->reason === BackupException::REASON_LOCATION);
    }

    #[Test]
    public function a_backup_directory_that_reaches_public_through_dot_dot_or_a_symlinked_parent_is_refused_before_it_exists(): void
    {
        Notification::fake();
        Exceptions::fake();
        $this->fakeTools();
        $name = 'crm-backups-escape-'.getmypid();

        // Relative, climbing out of storage into public/: resolved where it would really be created.
        config()->set('crm.backup.path', 'storage/../public/'.$name);
        $this->assertNotNull(app(BackupService::class)->locationProblem());
        $this->assertTrue(Paths::isInside(base_path('storage/../public/'.$name.'/nested/../deeper'), public_path()));
        $this->assertFalse(Paths::isInside(base_path('public/../storage/'.$name), public_path()));

        $this->artisan('crm:backup')
            ->expectsOutputToContain('[backup] FAILED (location): The backup directory')
            ->assertFailed();

        $this->assertDirectoryDoesNotExist(public_path($name));

        // A symlinked parent pointing into public/ (the documented public_html fallback).
        $link = $this->scratchBackupDirectory('links').DIRECTORY_SEPARATOR.'public_html';

        if (@symlink(public_path(), $link)) {
            try {
                config()->set('crm.backup.path', $link.DIRECTORY_SEPARATOR.$name);

                $this->artisan('crm:backup')
                    ->expectsOutputToContain('[backup] FAILED (location): The backup directory')
                    ->assertFailed();

                $this->assertDirectoryDoesNotExist(public_path($name));
            } finally {
                PHP_OS_FAMILY === 'Windows' ? @rmdir($link) : @unlink($link);
            }
        }

        $this->assertSame([], $this->runs, 'no tool ran');
    }

    #[Test]
    public function a_defaults_file_left_by_a_killed_run_is_removed_by_the_next_run(): void
    {
        $leftover = storage_path('framework'.DIRECTORY_SEPARATOR.'crm-backup-killed'.getmypid());
        $unrelated = storage_path('framework'.DIRECTORY_SEPARATOR.'not-a-crm-backup-'.getmypid());
        file_put_contents($leftover, "[client]\npassword=\"left behind\"\n");
        file_put_contents($unrelated, 'someone else');

        try {
            $this->fakeTools();

            $this->artisan('crm:backup')->assertSuccessful();

            $this->assertFileDoesNotExist($leftover);
            $this->assertFileExists($unrelated, 'only the backup\'s own defaults files are swept');
            $this->assertSame([], glob(storage_path('framework').DIRECTORY_SEPARATOR.'crm-backup-*') ?: []);
        } finally {
            @unlink($leftover);
            @unlink($unrelated);
        }
    }

    #[Test]
    public function tar_reporting_only_files_that_changed_while_archived_keeps_the_set(): void
    {
        Notification::fake();
        Exceptions::fake();
        $this->fakeTools(tarExit: 1, tarError: "tar: ./crm/contract.pdf: file changed as we read it\ntar: ./livewire-tmp/abc: File removed before we read it\n");

        $this->artisan('crm:backup')->assertSuccessful();

        $this->assertSame('20261001-220000', app(BackupService::class)->latest()?->id);
        $this->assertNull(app(BackupService::class)->lastFailure());
        Notification::assertNothingSent();

        // Exit code 1 with any other message (bsdtar's real errors, a GNU tar error line) stays a failure.
        $this->travel(1)->minutes();
        $this->fakeTools(tarExit: 1, tarError: "tar: ./crm/contract.pdf: file changed as we read it\ntar: ./crm/secret: Cannot open: Permission denied\n");

        $this->artisan('crm:backup')
            ->expectsOutputToContain('[backup] FAILED (files): tar exited with code 1')
            ->assertFailed();

        $this->travel(1)->minutes();
        $this->fakeTools(tarExit: 1);

        $this->artisan('crm:backup')->assertFailed();
    }

    #[Test]
    public function every_detail_the_service_can_record_reads_in_both_locales(): void
    {
        $source = (string) file_get_contents(app_path('Services/System/BackupService.php'));
        preg_match_all("/BackupException::\\w+\\('(\\w+)'/", $source, $calls);
        preg_match_all("/=> '(location_\\w+)'/", $source, $mapped);
        $details = array_unique([...$calls[1], ...$mapped[1], 'unexpected']);

        $this->assertGreaterThan(20, count($details));

        foreach ($details as $detail) {
            foreach (['ar', 'en'] as $locale) {
                $this->assertIsString(__('backups.details.'.$detail, [], $locale));
                $this->assertNotSame('backups.details.'.$detail, __('backups.details.'.$detail, [], $locale), "backups.details.{$detail} is missing in {$locale}");
            }
        }

        $this->assertSame(
            'The backup directory /srv/public/x lies under public/ — the database would be downloadable without authorisation.',
            BackupException::location('location_public', ['directory' => '/srv/public/x'])->getMessage(),
            'the log and the command output stay in English whatever the locale',
        );
    }

    #[Test]
    public function retention_keeps_the_newest_sets_and_touches_nothing_that_is_not_a_set(): void
    {
        config()->set('crm.backup.keep', 3);
        $this->fakeTools();

        $older = [];

        foreach ([7, 14, 21, 28] as $daysAgo) {
            $older[$daysAgo] = $this->makeBackupSet($this->backups, now()->subDays($daysAgo));
        }

        // Not sets: an incomplete set, an empty file in a set-shaped folder, a foreign folder and file.
        mkdir($this->backups.DIRECTORY_SEPARATOR.'20200101-000000');
        file_put_contents($this->backups.DIRECTORY_SEPARATOR.'20200101-000000'.DIRECTORY_SEPARATOR.'database.sql.gz', 'only half');
        mkdir($this->backups.DIRECTORY_SEPARATOR.'manual-copy');
        file_put_contents($this->backups.DIRECTORY_SEPARATOR.'README.txt', 'keep me');
        // A partial set a killed run abandoned.
        mkdir($this->backups.DIRECTORY_SEPARATOR.'.20200202-000000.partial');
        file_put_contents($this->backups.DIRECTORY_SEPARATOR.'.20200202-000000.partial'.DIRECTORY_SEPARATOR.'database.sql.gz', 'x');

        $this->artisan('crm:backup')
            ->expectsOutputToContain('3 sets kept.')
            ->assertSuccessful();

        $this->assertSame(
            ['20200101-000000', '20260917-220000', '20260924-220000', '20261001-220000', 'README.txt', 'manual-copy'],
            $this->entries($this->backups),
        );
        $this->assertSame(['20261001-220000', $older[7], $older[14]], array_map(static fn (BackupSet $set): string => $set->id, app(BackupService::class)->sets()));
    }

    #[Test]
    public function a_run_while_another_holds_the_lock_does_not_start_and_is_not_a_failure(): void
    {
        Notification::fake();
        Exceptions::fake();
        $this->fakeTools();
        $lock = Cache::lock(BackupService::LOCK, 60);
        $lock->get();

        try {
            $this->artisan('crm:backup')
                ->expectsOutputToContain('Another backup run is in progress')
                ->assertFailed();
        } finally {
            $lock->release();
        }

        $this->assertSame([], $this->runs);
        $this->assertNull(app(BackupService::class)->lastFailure());
        Notification::assertNothingSent();
        Exceptions::assertNothingReported();
    }

    #[Test]
    public function the_next_successful_run_clears_the_recorded_failure(): void
    {
        Notification::fake();
        Exceptions::fake();
        $this->fakeTools(dumpExit: 1);
        $this->artisan('crm:backup')->assertFailed();
        $this->assertNotNull(app(BackupService::class)->lastFailure());

        $this->travel(1)->minutes();
        $this->fakeTools();
        $this->artisan('crm:backup')->assertSuccessful();

        $this->assertNull(app(BackupService::class)->lastFailure());
        $this->assertSame(['20261001-220100'], $this->entries($this->backups));
    }

    #[Test]
    public function the_listing_returns_complete_sets_newest_first_and_find_resolves_only_listed_ids(): void
    {
        $backups = app(BackupService::class);
        $old = $this->makeBackupSet($this->backups, now()->subWeeks(2));
        $new = $this->makeBackupSet($this->backups, now()->subWeek(), str_repeat('d', 2048), str_repeat('f', 1024));
        mkdir($this->backups.DIRECTORY_SEPARATOR.'20261399-000000');
        file_put_contents($this->backups.DIRECTORY_SEPARATOR.'20261399-000000'.DIRECTORY_SEPARATOR.'database.sql.gz', 'x');
        file_put_contents($this->backups.DIRECTORY_SEPARATOR.'20261399-000000'.DIRECTORY_SEPARATOR.'files.tar.gz', 'x');

        $this->assertSame([$new, $old], array_map(static fn (BackupSet $set): string => $set->id, $backups->sets()), 'an impossible date is not a set');
        $latest = $backups->latest();
        $this->assertNotNull($latest);
        $this->assertSame(2048, $latest->databaseBytes);
        $this->assertSame(3072, $latest->totalBytes());
        $this->assertSame($old, $backups->find($old)?->id);
        $this->assertNull($backups->find('20261399-000000'));
        $this->assertNull($backups->find('../'.$old));
        $this->assertNull($backups->find('20200101-000000'));
    }

    #[Test]
    public function a_set_older_than_eight_days_or_none_at_all_is_stale(): void
    {
        $backups = app(BackupService::class);
        $this->assertTrue($backups->isStale());

        $this->makeBackupSet($this->backups, now()->subDays(9));
        $this->assertTrue($backups->isStale());

        $this->makeBackupSet($this->backups, now()->subDays(7));
        $this->assertFalse($backups->isStale());
    }

    #[Test]
    public function the_queued_run_is_attributed_to_the_super_admin_who_asked_and_swallows_reported_failures(): void
    {
        $admin = $this->superAdmin();
        $this->fakeTools();

        (new CreateBackup($admin->getKey()))->handle(app(BackupService::class));

        $this->assertSame($admin->getKey(), (int) ActivityLog::query()->where('description', ActivityLogEvent::BackupCreated->value)->sole()->causer_id);

        Notification::fake();
        Exceptions::fake();
        $this->travel(1)->minutes();
        $this->fakeTools(dumpExit: 1);

        (new CreateBackup($admin->getKey()))->handle(app(BackupService::class));

        Notification::assertSentTo($admin, BackupFailedNotification::class);

        $job = new CreateBackup($admin->getKey());
        $this->assertSame(1, $job->tries);
        $this->assertSame(BackupService::LOCK_SECONDS, $job->timeout);
    }

    #[Test]
    public function the_queued_run_leaves_the_queue_before_the_long_run_starts(): void
    {
        $admin = $this->superAdmin();
        $job = (new CreateBackup($admin->getKey()))->withFakeQueueInteractions();
        $deletedWhenDumpStarted = null;

        Process::fake(function (PendingProcess $process) use ($job, &$deletedWhenDumpStarted) {
            /** @var list<string> $command */
            $command = is_array($process->command) ? array_values($process->command) : [(string) $process->command];

            if ($command[0] === 'tar') {
                file_put_contents($command[2], 'tar');

                return Process::result();
            }

            $deletedWhenDumpStarted ??= $job->job?->isDeleted();

            foreach ($command as $argument) {
                if (str_starts_with($argument, '--result-file=')) {
                    file_put_contents(substr($argument, strlen('--result-file=')), self::DUMP);
                }
            }

            return Process::result();
        });

        $job->handle(app(BackupService::class));

        $this->assertTrue($deletedWhenDumpStarted, 'a later drain must find no reserved row to re-offer (and fail) mid-run');
        $job->assertDeleted();
        $job->assertNotFailed();
        $this->assertNotNull(app(BackupService::class)->latest());
    }

    #[Test]
    public function the_backup_is_scheduled_thursdays_at_22_on_one_server_without_overlapping(): void
    {
        $this->app->make(Kernel::class)->all();

        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn (Event $event): bool => str_contains((string) $event->command, 'crm:backup'),
        ));

        $this->assertCount(1, $events);
        $this->assertSame('0 22 * * 4', $events[0]->expression);
        $this->assertTrue($events[0]->onOneServer);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertSame(120, $events[0]->expiresAt);
    }

    #[Test]
    public function the_configuration_defaults_keep_eight_sets_under_storage_app_backups(): void
    {
        config()->set('crm.backup.path', null);

        $this->assertSame(storage_path('app/backups'), app(BackupService::class)->directory());

        $shipped = require config_path('crm.php');
        $this->assertIsArray($shipped);
        $this->assertSame(storage_path('app/backups'), $shipped['backup']['path']);
        $this->assertSame(8, $shipped['backup']['keep']);
        $this->assertSame('mysqldump', $shipped['backup']['mysqldump']);
        $this->assertNull(app(BackupService::class)->locationProblem());

        config()->set('crm.backup.path', 'backups-relative');
        $this->assertSame(base_path('backups-relative'), app(BackupService::class)->directory());

        // A blank or null CRM_BACKUP_KEEP falls back to 8 like the other keys, never to 0 (prune would keep 1).
        foreach (['', 'null', '12'] as $value) {
            $_ENV['CRM_BACKUP_KEEP'] = $_SERVER['CRM_BACKUP_KEEP'] = $value;

            try {
                $reloaded = require config_path('crm.php');
            } finally {
                unset($_ENV['CRM_BACKUP_KEEP'], $_SERVER['CRM_BACKUP_KEEP']);
            }

            $this->assertIsArray($reloaded);
            $this->assertSame($value === '12' ? 12 : 8, $reloaded['backup']['keep'], "CRM_BACKUP_KEEP={$value}");
        }
    }

    /**
     * Fakes mysqldump and tar: each writes the file the real tool would
     * (unless told not to) and answers with the given exit code, and every
     * call is recorded with the defaults file as it was while the tool ran.
     */
    private function fakeTools(int $dumpExit = 0, string $dumpError = '', bool $dumpWrites = true, int $tarExit = 0, string $tarError = '', bool $tarWrites = true): void
    {
        $this->runs = [];

        Process::fake(function (PendingProcess $process) use ($dumpExit, $dumpError, $dumpWrites, $tarExit, $tarError, $tarWrites) {
            /** @var list<string> $command */
            $command = is_array($process->command) ? array_values($process->command) : [(string) $process->command];

            if ($command[0] === 'tar') {
                $this->runs[] = ['command' => $command, 'defaults' => null, 'permissions' => null];

                // GNU tar still writes the archive when it exits 1 ("some files differ").
                if ($tarWrites && in_array($tarExit, [0, 1], true)) {
                    file_put_contents($command[2], 'tar of '.$command[4]);
                }

                return Process::result(errorOutput: $tarError, exitCode: $tarExit);
            }

            $defaults = substr($command[1], strlen('--defaults-extra-file='));
            $this->runs[] = [
                'command' => $command,
                'defaults' => is_file($defaults) ? (string) file_get_contents($defaults) : null,
                'permissions' => is_file($defaults) ? substr(sprintf('%o', fileperms($defaults)), -4) : null,
            ];

            foreach ($command as $argument) {
                if ($dumpWrites && $dumpExit === 0 && str_starts_with($argument, '--result-file=')) {
                    file_put_contents(substr($argument, strlen('--result-file=')), self::DUMP);
                }
            }

            return Process::result(errorOutput: $dumpError, exitCode: $dumpExit);
        });
    }

    /**
     * One value of a MySQL / MariaDB option file, read the way their clients
     * read it (mysys my_default: remove_end_comment() cuts an unquoted `#`
     * comment while tracking quotes and backslash escapes, matching outer
     * quotes are stripped, then `\\`, `\"`, `\'`, `\n`, `\t`, `\r`, `\b`, `\s`
     * are unescaped).
     */
    private function optionFileValue(string $file, string $option): ?string
    {
        foreach (preg_split('/\R/', $file) ?: [] as $line) {
            if (! str_starts_with($line, $option.'=')) {
                continue;
            }

            $value = substr($line, strlen($option) + 1);
            $quote = '';
            $escape = false;

            for ($i = 0, $length = strlen($value); $i < $length; $i++) {
                $char = $value[$i];

                if (($char === '"' || $char === "'") && ! $escape) {
                    $quote = $quote === '' ? $char : ($quote === $char ? '' : $quote);
                }

                if ($quote === '' && $char === '#') {
                    $value = substr($value, 0, $i);

                    break;
                }

                $escape = $quote !== '' && $char === '\\' && ! $escape;
            }

            $value = rtrim($value);

            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === $value[strlen($value) - 1]) {
                $value = substr($value, 1, -1);
            }

            return strtr($value, ['\\\\' => '\\', '\\"' => '"', "\\'" => "'", '\\n' => "\n", '\\t' => "\t", '\\r' => "\r", '\\b' => "\x08", '\\s' => ' ']);
        }

        return null;
    }

    /**
     * @return list<string> the names in a directory, sorted, without . and ..
     */
    private function entries(string $directory): array
    {
        $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }
}
