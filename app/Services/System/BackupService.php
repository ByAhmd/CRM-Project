<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Enums\ActivityLogEvent;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Jobs\CreateBackup;
use App\Models\User;
use App\Notifications\BackupFailedNotification;
use App\Services\Audit\AuditLogger;
use App\Support\System\Paths;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * The CRM's own weekly backup (decision D-16).
 *
 * A run writes one set — a gzip'd `mysqldump --single-transaction --routines
 * --triggers --no-tablespaces` of the application database and a tar.gz of
 * the private storage disk (attachments, exports) — into a hidden
 * `.<id>.partial` folder of the backup directory (crm.backup.path), checks
 * that both files exist and are non-empty, and only then renames the folder
 * to its id, so a killed run never leaves something that looks like a set.
 * After a success the newest crm.backup.keep sets stay and older ones (plus
 * partial folders a killed run abandoned) are deleted; nothing outside the
 * backup directory, and nothing in it that is not a set or a partial set, is
 * ever touched.
 *
 * Credentials reach mysqldump through a temporary defaults file created with
 * mode 0600 and deleted in `finally` — never the command line, the log or an
 * exception message. A run the host killed outright (no `finally`) leaves that
 * file behind; the next run removes every such leftover while it holds the
 * lock, before it writes its own.
 *
 * A failure is never silent: the partial folder is removed, the exception is
 * reported, the failure is recorded next to the sets (lastFailure(), read by
 * the Backups page and the weekly summary, cleared by the next success), the
 * audit ledger gets `backup.failed`, and every active holder of `roles.manage`
 * — the users who see System → Backups — gets BackupFailedNotification (bell,
 * and mail on by default).
 *
 * One run at a time: the scheduled run and a *Back up now* share a cache lock.
 */
final class BackupService
{
    public const string LOCK = 'crm-backup';

    /** Longer than any run may take; a crashed run's lock expires by itself. */
    public const int LOCK_SECONDS = 7200;

    /** Each of mysqldump and tar is stopped after an hour. */
    public const int PROCESS_TIMEOUT = 3600;

    /** app:preflight warns when the newest set is older than this. */
    public const int STALE_AFTER_DAYS = 8;

    private const string FAILURE_FILE = 'last-failure.json';

    private const string PARTIAL_PATTERN = '/^\.(\d{8}-\d{6})\.partial$/';

    /** tempnam() prefix of the mysqldump defaults file under storage/framework. */
    private const string DEFAULTS_PREFIX = 'crm-backup-';

    /**
     * GNU tar exits 1 ("some files differ") when a file of the live private
     * disk changes or disappears while it is archived; the archive is still
     * complete. Only these messages make exit code 1 acceptable.
     */
    private const string TAR_BENIGN_PATTERN = '/(file changed as we read it|file removed before we read it|file shrank by \d+ bytes?; padding with zeros|socket ignored)\s*$/i';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Queues a run for the next scheduler drain (the *Back up now* action).
     * CreateBackup is unique, so a second click before it ran queues nothing.
     */
    public function queue(User $requestedBy): void
    {
        CreateBackup::dispatch($requestedBy->getKey());
    }

    /**
     * @throws BackupInProgressException when another run holds the lock
     * @throws BackupException when no complete set was written (already reported and notified)
     */
    public function run(?User $causer = null): BackupSet
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw BackupInProgressException::make();
        }

        try {
            $this->removeLeftoverDefaultsFiles();

            return $this->create($causer);
        } finally {
            $lock->release();
        }
    }

    /**
     * Every complete set, newest first.
     *
     * @return list<BackupSet>
     */
    public function sets(): array
    {
        $root = $this->directory();

        if (! is_dir($root)) {
            return [];
        }

        $sets = [];

        foreach (@scandir($root) ?: [] as $entry) {
            $set = $this->read($root, $entry);

            if ($set !== null) {
                $sets[] = $set;
            }
        }

        usort($sets, static fn (BackupSet $a, BackupSet $b): int => strcmp($b->id, $a->id));

        return $sets;
    }

    public function latest(): ?BackupSet
    {
        return $this->sets()[0] ?? null;
    }

    /** A set by its id, only when it is one of the listed complete sets. */
    public function find(string $id): ?BackupSet
    {
        if (! BackupSet::isId($id)) {
            return null;
        }

        foreach ($this->sets() as $set) {
            if ($set->id === $id) {
                return $set;
            }
        }

        return null;
    }

    /** The failed run recorded since the last success, if any. */
    public function lastFailure(): ?BackupFailure
    {
        $file = $this->directory().DIRECTORY_SEPARATOR.self::FAILURE_FILE;

        if (! is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? BackupFailure::fromArray($data) : null;
    }

    /** Whether the newest set is missing or older than STALE_AFTER_DAYS (app:preflight). */
    public function isStale(): bool
    {
        $latest = $this->latest();

        return $latest === null || $latest->createdAt->lessThan(now()->subDays(self::STALE_AFTER_DAYS));
    }

    /**
     * Streams one file of a set to a super admin and records the download in
     * the audit ledger: a backup is the whole database, so who took a copy
     * matters as much as who opened an attachment.
     */
    public function download(BackupSet $set, string $part, User $user): BinaryFileResponse
    {
        $response = response()->download($set->path($part), $set->downloadName($part), [
            'Content-Type' => 'application/gzip',
            'Cache-Control' => 'no-store, private',
        ]);

        $this->audit->record(ActivityLogEvent::BackupDownloaded, null, $user, [
            'subject_label' => $set->downloadName($part),
            'downloaded_by' => $user->getKey(),
        ]);

        return $response;
    }

    /**
     * The backup directory as configured (absolute; a relative value is taken
     * from the application root). Not created and not validated here.
     */
    public function directory(): string
    {
        $configured = trim((string) config('crm.backup.path'));
        $path = $configured === '' ? storage_path('app/backups') : $configured;

        if (! Paths::isAbsolute($path)) {
            $path = base_path($path);
        }

        return rtrim($path, '/\\');
    }

    /**
     * Why the backup directory must not be used, or null when it is safe: it
     * may lie neither under public/ (anyone could download the database) nor
     * inside a disk the web server serves or the backup archives.
     */
    public function locationProblem(): ?string
    {
        return $this->locationFailure()?->getMessage();
    }

    /**
     * The same check as locationProblem(), as the exception a run fails with.
     * Paths resolves a directory that does not exist yet through its existing
     * parents, so a `..` segment or a symlinked parent is judged where the
     * directory would really be created.
     */
    private function locationFailure(): ?BackupException
    {
        $directory = $this->directory();

        if (Paths::isInside($directory, public_path())) {
            return BackupException::location('location_public', ['directory' => $directory]);
        }

        foreach (['local' => 'location_private_disk', 'public' => 'location_public_disk'] as $disk => $detail) {
            $root = (string) config('filesystems.disks.'.$disk.'.root');

            if ($root !== '' && Paths::isInside($directory, $root)) {
                return BackupException::location($detail, ['directory' => $directory, 'root' => $root]);
            }
        }

        return null;
    }

    private function create(?User $causer): BackupSet
    {
        $startedAt = CarbonImmutable::now((string) config('app.timezone'));
        $id = $startedAt->format(BackupSet::ID_FORMAT);
        $root = null;
        $partial = null;

        try {
            $root = $this->prepareDirectory();
            $final = $root.DIRECTORY_SEPARATOR.$id;

            if (file_exists($final)) {
                throw BackupException::location('set_exists', ['id' => $id, 'directory' => $root]);
            }

            $partial = $root.DIRECTORY_SEPARATOR.'.'.$id.'.partial';

            if (! is_dir($partial) && ! @mkdir($partial, 0700)) {
                throw BackupException::location('set_folder', ['path' => $partial]);
            }

            $this->dumpDatabase($partial.DIRECTORY_SEPARATOR.BackupSet::FILE_NAMES[BackupSet::DATABASE]);
            $this->archiveFiles($partial.DIRECTORY_SEPARATOR.BackupSet::FILE_NAMES[BackupSet::FILES]);

            foreach (BackupSet::FILE_NAMES as $name) {
                $file = $partial.DIRECTORY_SEPARATOR.$name;
                clearstatcache(true, $file);

                if (! is_file($file) || (int) filesize($file) === 0) {
                    throw BackupException::incomplete('file_missing', ['file' => $name]);
                }
            }

            if (! @rename($partial, $final)) {
                throw BackupException::location('rename', ['from' => $partial, 'to' => $final]);
            }
        } catch (Throwable $exception) {
            if ($partial !== null && is_dir($partial)) {
                File::deleteDirectory($partial);
            }

            throw $this->fail($exception, $startedAt, $id, $root, $causer);
        }

        @unlink($root.DIRECTORY_SEPARATOR.self::FAILURE_FILE);

        $set = $this->read($root, $id) ?? throw $this->fail(
            BackupException::incomplete('unreadable', ['id' => $id]),
            $startedAt,
            $id,
            $root,
            $causer,
        );

        $this->prune($root);

        $this->audit->record(ActivityLogEvent::BackupCreated, null, $causer, [
            'subject_label' => $set->id,
        ]);

        return $set;
    }

    /**
     * Creates the backup directory when missing, after refusing an unsafe one,
     * and checks it once more where it really landed before anything is
     * written into it.
     */
    private function prepareDirectory(): string
    {
        $problem = $this->locationFailure();

        if ($problem !== null) {
            throw $problem;
        }

        $root = $this->directory();

        if (! is_dir($root) && ! @mkdir($root, 0700, true) && ! is_dir($root)) {
            throw BackupException::location('directory_create', ['directory' => $root]);
        }

        $problem = $this->locationFailure();

        if ($problem !== null) {
            throw $problem;
        }

        if (! is_writable($root)) {
            throw BackupException::location('directory_writable', ['directory' => $root]);
        }

        return $root;
    }

    private function dumpDatabase(string $target): void
    {
        if (! function_exists('gzopen')) {
            throw BackupException::database('zlib_missing');
        }

        // The connection as configured, with a DB_URL folded in exactly as the database manager does.
        $connection = (string) config('database.default');
        $configured = config('database.connections.'.$connection);
        $config = (new ConfigurationUrlParser)->parseConfiguration(is_array($configured) ? $configured : []);
        $driver = (string) ($config['driver'] ?? '');

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw BackupException::database('driver', ['connection' => $connection, 'driver' => $driver]);
        }

        $database = (string) ($config['database'] ?? '');

        if ($database === '') {
            throw BackupException::database('no_database', ['connection' => $connection]);
        }

        $binary = trim((string) config('crm.backup.mysqldump'));
        $binary = $binary === '' ? 'mysqldump' : $binary;
        $plain = $target.'.sql-plain';
        $defaults = $this->writeDefaultsFile($config);

        try {
            $result = Process::timeout(self::PROCESS_TIMEOUT)->run([
                $binary,
                '--defaults-extra-file='.$defaults,
                '--single-transaction',
                '--routines',
                '--triggers',
                '--no-tablespaces',
                // MySQL's client otherwise reads the GTID position under FLUSH TABLES, which needs the
                // RELOAD privilege a shared-hosting user never has, and writes a SET @@GLOBAL.GTID_PURGED
                // that a restore could not run either. `loose-`: MariaDB's client only warns and goes on.
                '--loose-set-gtid-purged=OFF',
                '--default-character-set='.((string) ($config['charset'] ?? '') ?: 'utf8mb4'),
                '--result-file='.$plain,
                $database,
            ]);
        } catch (Throwable $exception) {
            throw BackupException::database('tool_not_run', ['tool' => $binary, 'error' => $exception->getMessage()], $exception);
        } finally {
            @unlink($defaults);
        }

        if (! $result->successful()) {
            throw BackupException::database('tool_failed', [
                'tool' => $binary,
                'code' => (string) $result->exitCode(),
                'output' => $this->excerpt($result->errorOutput().$result->output()),
            ]);
        }

        clearstatcache(true, $plain);

        if (! is_file($plain) || (int) filesize($plain) === 0) {
            throw BackupException::database('no_dump', ['tool' => $binary]);
        }

        try {
            $this->compress($plain, $target);
        } finally {
            @unlink($plain);
        }
    }

    /**
     * The `[client]` option file mysqldump reads the connection from. Values
     * are double-quoted with backslashes and double quotes escaped (`\\`,
     * `\"`): the MySQL and MariaDB option parsers track quotes to find a
     * trailing `#` comment, so an unescaped `"` inside a value would end the
     * quoting early and cut the value at a later `#`. A value holding a line
     * break cannot be written safely and is refused.
     *
     * @param  array<mixed>  $config
     */
    private function writeDefaultsFile(array $config): string
    {
        $options = ['user' => (string) ($config['username'] ?? '')];
        $password = (string) ($config['password'] ?? '');
        $socket = (string) ($config['unix_socket'] ?? '');

        if ($password !== '') {
            $options['password'] = $password;
        }

        if ($socket !== '') {
            $options['socket'] = $socket;
        } else {
            $options['host'] = (string) ($config['host'] ?? '127.0.0.1');
            $options['port'] = (string) ($config['port'] ?? '3306');
        }

        $lines = ['[client]'];

        foreach ($options as $key => $value) {
            if (str_contains($value, "\n") || str_contains($value, "\r")) {
                throw BackupException::database('line_break', ['option' => $key]);
            }

            $lines[] = $key.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        // Named here rather than by tempnam(), which keeps only three prefix characters on Windows,
        // so removeLeftoverDefaultsFiles() recognises every file this method ever created.
        $file = storage_path('framework').DIRECTORY_SEPARATOR.self::DEFAULTS_PREFIX.bin2hex(random_bytes(8));
        $umask = umask(0077);

        try {
            $handle = @fopen($file, 'x');
        } finally {
            umask($umask);
        }

        if ($handle === false) {
            throw BackupException::database('defaults_create');
        }

        fclose($handle);
        @chmod($file, 0600);

        if (@file_put_contents($file, implode("\n", $lines)."\n") === false) {
            @unlink($file);

            throw BackupException::database('defaults_write');
        }

        return $file;
    }

    /**
     * Deletes the mysqldump defaults files runs killed mid-dump left under
     * storage/framework (their `finally` never ran). Called with the backup
     * lock held, so no other run owns one of them.
     */
    private function removeLeftoverDefaultsFiles(): void
    {
        foreach (glob(storage_path('framework').DIRECTORY_SEPARATOR.self::DEFAULTS_PREFIX.'*') ?: [] as $file) {
            if (is_file($file) && ! is_link($file)) {
                @unlink($file);
            }
        }
    }

    private function compress(string $source, string $target): void
    {
        $in = @fopen($source, 'rb');
        $out = @gzopen($target, 'wb6');

        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }

            throw BackupException::database('compress_open');
        }

        try {
            while (! feof($in)) {
                $chunk = fread($in, 1024 * 1024);

                if ($chunk === false) {
                    throw BackupException::database('compress_read');
                }

                if ($chunk !== '' && gzwrite($out, $chunk) !== strlen($chunk)) {
                    throw BackupException::database('compress_write');
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
    }

    private function archiveFiles(string $target): void
    {
        $source = (string) config('filesystems.disks.local.root');

        if ($source === '') {
            throw BackupException::files('private_disk_root');
        }

        if (! is_dir($source) && ! @mkdir($source, 0755, true) && ! is_dir($source)) {
            throw BackupException::files('private_disk_create', ['root' => $source]);
        }

        try {
            $result = Process::timeout(self::PROCESS_TIMEOUT)->run(['tar', '-czf', $target, '-C', $source, '.']);
        } catch (Throwable $exception) {
            throw BackupException::files('tool_not_run', ['tool' => 'tar', 'error' => $exception->getMessage()], $exception);
        }

        if ($result->successful()) {
            return;
        }

        $output = $result->errorOutput().$result->output();

        if ($result->exitCode() === 1 && $this->onlyChangedWhileRead($output)) {
            // The archive is complete apart from files that changed under it; the set stands.
            Log::warning('[backup] tar reported files that changed while they were archived; the set is kept.', [
                'output' => $this->excerpt($output),
            ]);

            return;
        }

        throw BackupException::files('tool_failed', [
            'tool' => 'tar',
            'code' => (string) $result->exitCode(),
            'output' => $this->excerpt($output),
        ]);
    }

    /**
     * Whether tar's output holds at least one message and only GNU tar's
     * "file changed / removed while archived" warnings. Any other message
     * (bsdtar, which exits 1 on real errors too, never prints these) keeps
     * exit code 1 a failure.
     */
    private function onlyChangedWhileRead(string $output): bool
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $output) ?: []),
            static fn (string $line): bool => $line !== '',
        ));

        if ($lines === []) {
            return false;
        }

        foreach ($lines as $line) {
            if (preg_match(self::TAR_BENIGN_PATTERN, $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Keeps the newest crm.backup.keep sets (at least one) and removes older
     * ones, plus partial folders a killed run left behind — the lock is held,
     * so no other run can own one. Only folders the listing produced, or that
     * match the partial pattern, inside the backup directory are deleted.
     */
    private function prune(string $root): void
    {
        $keep = max(1, (int) config('crm.backup.keep'));

        try {
            foreach (array_slice($this->sets(), $keep) as $set) {
                File::deleteDirectory($set->directory);
            }

            foreach (@scandir($root) ?: [] as $entry) {
                $path = $root.DIRECTORY_SEPARATOR.$entry;

                if (preg_match(self::PARTIAL_PATTERN, $entry) === 1 && is_dir($path) && ! is_link($path)) {
                    File::deleteDirectory($path);
                }
            }
        } catch (Throwable $exception) {
            // The new set is complete; a set that could not be pruned is retried after the next run.
            report($exception);
        }
    }

    private function read(string $root, string $entry): ?BackupSet
    {
        $createdAt = BackupSet::parseId($entry);
        $directory = $root.DIRECTORY_SEPARATOR.$entry;

        if ($createdAt === null || ! is_dir($directory) || is_link($directory)) {
            return null;
        }

        $sizes = [];

        foreach (BackupSet::FILE_NAMES as $part => $name) {
            $file = $directory.DIRECTORY_SEPARATOR.$name;

            if (! is_file($file) || is_link($file)) {
                return null;
            }

            $size = (int) filesize($file);

            if ($size === 0) {
                return null;
            }

            $sizes[$part] = $size;
        }

        return new BackupSet($entry, $createdAt, $directory, $sizes[BackupSet::DATABASE], $sizes[BackupSet::FILES]);
    }

    /**
     * Reports the failure, records it next to the sets, audits it and tells
     * every active super admin. Each side effect is guarded on its own: a
     * failure caused by a dead database must still reach the log.
     */
    private function fail(Throwable $exception, CarbonImmutable $startedAt, string $id, ?string $root, ?User $causer): BackupException
    {
        $failure = $exception instanceof BackupException ? $exception : BackupException::unexpected($exception);

        report($failure);

        $record = BackupFailure::fromException($failure, $startedAt);

        if ($root !== null && is_dir($root)) {
            @file_put_contents($root.DIRECTORY_SEPARATOR.self::FAILURE_FILE, (string) json_encode($record->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        try {
            $this->audit->record(ActivityLogEvent::BackupFailed, null, $causer, [
                'subject_label' => $id,
            ]);
        } catch (Throwable $auditFailure) {
            report($auditFailure);
        }

        try {
            Notification::send($this->failureRecipients(), new BackupFailedNotification($record));
        } catch (Throwable $notificationFailure) {
            report($notificationFailure);
        }

        return $failure;
    }

    /**
     * Active holders of `roles.manage`, through a role or directly: the users
     * who open System → Backups, read the health part of the weekly summary
     * and are offered this notice on their preferences page (D-16, D-19). The
     * seeded super admin role holds it; a custom role granted it counts too.
     *
     * @return Collection<int, User>
     */
    private function failureRecipients(): Collection
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->permission(Permission::RolesManage->value)
            ->get();
    }

    /** The last 500 characters of a tool's output, on one line. */
    private function excerpt(string $output): string
    {
        $output = trim((string) preg_replace('/\s+/', ' ', $output));

        return $output === '' ? '(no output)' : Str::substr($output, -500);
    }
}
