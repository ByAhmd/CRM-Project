<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActivityKind;
use App\Enums\CrmRole;
use App\Enums\LeadStatusKind;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Http\Middleware\TrustProxies;
use App\Models\ActivityType;
use App\Models\LeadStatus;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Support\System\PlatformRequirements;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Production preflight (docs/ARCHITECTURE_PLAN.md section 9).
 *
 * Run by the deploy script while the site is still in maintenance mode, so a
 * dangerous configuration fails the deploy instead of going live.
 *
 * FAILURES (exit 1):
 * - everywhere: PHP older than 8.3 or a required extension missing
 *   (PlatformRequirements); a blank APP_KEY; the attachments disk rooted
 *   under public/ (private files would be downloadable without the
 *   authorised route, D-13); storage/app, storage/logs or bootstrap/cache
 *   not writable; a database that is unreachable, has pending migrations or
 *   lacks what the CRM needs to run — the permission catalogue (one row per
 *   App\Enums\Permission case), an active super admin, a default lead status
 *   and the Converted status, a default pipeline with a default stage, one
 *   system activity type per ActivityKind;
 * - in production only: APP_DEBUG=true, a non-https APP_URL,
 *   SESSION_SECURE_COOKIE not true (D-11), the sync queue (D-1), the array
 *   cache store.
 *
 * WARNINGS (exit 0): degraded-but-operable states the operator should know
 * about — everywhere: the log or array mailer (D-10 allows it until
 * production SMTP exists) and a scheduler heartbeat missing or older than
 * five minutes (the host's cron is not running `schedule:run`); in
 * production: SESSION_LIFETIME other than 120 (D-11), configuration, routes,
 * views or Filament components not cached, and no trusted proxies configured
 * (behind the host's proxy, HTTPS detection, secure cookies and client IPs
 * depend on crm.trusted_proxies).
 *
 * `--json` prints {status, failures[], warnings[]} for CI and scripts, with
 * the same exit codes. Operator-facing CLI output is plain English, read by
 * engineers in deploy logs, like app:onboard.
 */
final class PreflightCommand extends Command
{
    public const HEARTBEAT_KEY = 'scheduler.heartbeat';

    public const HEARTBEAT_MAX_AGE_MINUTES = 5;

    public const SESSION_LIFETIME_MINUTES = 120;

    protected $signature = 'app:preflight
        {--json : print {status, failures[], warnings[]} as JSON (same exit codes)}';

    protected $description = 'Verify production configuration and reference data before the site leaves maintenance mode';

    public function handle(PlatformRequirements $platform): int
    {
        $production = $this->laravel->environment('production');
        $failures = [];
        $warnings = [];

        array_push($failures, ...$this->platformFailures($platform));

        if (blank(config('app.key'))) {
            $failures[] = 'APP_KEY is not set — encrypted casts and sessions cannot work.';
        }

        if ($production) {
            array_push($failures, ...$this->productionFailures());
            array_push($warnings, ...$this->productionWarnings());
        }

        array_push($failures, ...$this->filesystemFailures());
        array_push($failures, ...$this->databaseFailures());

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $warnings[] = sprintf(
                'MAIL_MAILER is "%s" — invitations, password resets and mail notifications are not delivered (D-10).',
                (string) config('mail.default'),
            );
        }

        if (($heartbeat = $this->heartbeatWarning()) !== null) {
            $warnings[] = $heartbeat;
        }

        return $this->report($failures, $warnings);
    }

    /**
     * @param  list<string>  $failures
     * @param  list<string>  $warnings
     */
    private function report(array $failures, array $warnings): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'status' => $failures === [] ? 'ok' : 'failed',
                'failures' => $failures,
                'warnings' => $warnings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failures === [] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($warnings as $warning) {
            $this->warn('[preflight] WARN: '.$warning);
        }

        foreach ($failures as $failure) {
            $this->error('[preflight] FAIL: '.$failure);
        }

        if ($failures !== []) {
            $this->error('[preflight] Refusing to go live. Fix the failures above; the site stays in maintenance mode.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            '[preflight] OK (%s environment, %d warning%s).',
            (string) $this->laravel->environment(),
            count($warnings),
            count($warnings) === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function platformFailures(PlatformRequirements $platform): array
    {
        $failures = [];

        if (! $platform->phpIsSupported()) {
            $failures[] = sprintf('PHP %s is running; the CRM needs PHP %s or newer (D-1).', $platform->phpVersion(), PlatformRequirements::MINIMUM_PHP);
        }

        $missing = $platform->missingExtensions();

        if ($missing !== []) {
            $failures[] = sprintf(
                'Required PHP extension%s not loaded: %s — enable %s in the host\'s PHP configuration.',
                count($missing) === 1 ? ' is' : 's are',
                implode(', ', $missing),
                count($missing) === 1 ? 'it' : 'them',
            );
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    private function productionFailures(): array
    {
        $failures = [];

        if (config('app.debug') === true) {
            $failures[] = 'APP_DEBUG is true in production — stack traces and configuration would be exposed.';
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $failures[] = 'APP_URL is not https in production — signed URLs and cookies would be issued for plain HTTP.';
        }

        if (config('session.secure') !== true) {
            $failures[] = 'SESSION_SECURE_COOKIE is not true — session cookies may travel over plain HTTP (D-11).';
        }

        if (config('queue.default') === 'sync') {
            $failures[] = 'QUEUE_CONNECTION is sync — notifications, reminders and imports would run inside requests.';
        }

        if (config('cache.default') === 'array') {
            $failures[] = 'CACHE_STORE is array — nothing survives the request: the scheduler lock, login throttling, settings and permissions caches do not work.';
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    private function productionWarnings(): array
    {
        $warnings = [];

        if ((int) config('session.lifetime') !== self::SESSION_LIFETIME_MINUTES) {
            $warnings[] = sprintf('SESSION_LIFETIME is %d minutes; the decided lifetime is %d (D-11).', (int) config('session.lifetime'), self::SESSION_LIFETIME_MINUTES);
        }

        $uncached = [];

        if (! $this->laravel->configurationIsCached()) {
            $uncached[] = 'configuration';
        }

        if (! $this->laravel->routesAreCached()) {
            $uncached[] = 'routes';
        }

        if (! $this->viewsAreCached()) {
            $uncached[] = 'views';
        }

        if (! $this->filamentComponentsAreCached()) {
            $uncached[] = 'Filament components';
        }

        if ($uncached !== []) {
            $warnings[] = sprintf(
                'Not cached: %s — run `php artisan optimize` and `php artisan filament:optimize` after every deploy (plan section 8).',
                implode(', ', $uncached),
            );
        }

        if (TrustProxies::parse(config('crm.trusted_proxies')) === null) {
            $warnings[] = 'CRM_TRUSTED_PROXIES is not set — behind the host\'s proxy, HTTPS detection, secure cookies and client IP addresses (login throttling, audit rows) need crm.trusted_proxies.';
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function filesystemFailures(): array
    {
        $failures = [];
        $disk = (string) config('crm.attachments.disk');
        $settings = config('filesystems.disks.'.$disk);

        if (! is_array($settings)) {
            $failures[] = sprintf('The attachments disk "%s" is not defined in config/filesystems.php.', $disk);
        } elseif (($settings['driver'] ?? null) === 'local' && $this->isInside((string) ($settings['root'] ?? ''), public_path())) {
            $failures[] = sprintf('The attachments disk "%s" is rooted under public/ — private files would be downloadable without authorisation (D-13).', $disk);
        }

        $directories = [
            'storage/app' => storage_path('app'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => $this->laravel->bootstrapPath('cache'),
        ];

        foreach ($directories as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $failures[] = sprintf('%s is not writable (%s) — uploads, logs and caches cannot be written.', $label, $path);
            }
        }

        return $failures;
    }

    /**
     * The schema must be current before its rows are inspected: a pending
     * migration may be the one that creates or reshapes a lookup table.
     *
     * @return list<string>
     */
    private function databaseFailures(): array
    {
        try {
            $pending = $this->pendingMigrations();
        } catch (Throwable $exception) {
            return ['The database cannot be reached or read — '.$exception->getMessage()];
        }

        if ($pending === null) {
            return ['The database has never been migrated — run `php artisan migrate --force`.'];
        }

        if ($pending !== []) {
            return [sprintf(
                '%d migration%s pending (first: %s) — run `php artisan migrate --force`.',
                count($pending),
                count($pending) === 1 ? ' is' : 's are',
                $pending[0],
            )];
        }

        return [...$this->accessFailures(), ...$this->referenceDataFailures()];
    }

    /**
     * @return list<string>|null null when the migrations table does not exist
     */
    private function pendingMigrations(): ?array
    {
        $migrator = $this->laravel->make(Migrator::class);

        if (! $migrator->repositoryExists()) {
            return null;
        }

        $files = $migrator->getMigrationFiles(array_merge([$this->laravel->databasePath('migrations')], $migrator->paths()));
        $ran = $migrator->getRepository()->getRan();

        return array_values(array_diff(array_keys($files), $ran));
    }

    /**
     * @return list<string>
     */
    private function accessFailures(): array
    {
        $failures = [];
        $expected = count(Permission::cases());
        $seeded = DB::table((string) config('permission.table_names.permissions', 'permissions'))->count();

        if ($seeded !== $expected) {
            $failures[] = sprintf(
                'The permissions table has %d row%s; the catalogue (App\Enums\Permission) has %d — run `php artisan db:seed --force` (D-3).',
                $seeded,
                $seeded === 1 ? '' : 's',
                $expected,
            );
        }

        $superAdmin = User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', CrmRole::SuperAdmin->value))
            ->exists();

        if (! $superAdmin) {
            $failures[] = 'No active super admin — nobody can administer roles or invite users (A-12); run `php artisan app:onboard`.';
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    private function referenceDataFailures(): array
    {
        $failures = [];
        $reseed = 'run `php artisan db:seed --force` (or `php artisan app:onboard` on a fresh install).';

        if (LeadStatus::query()->where('is_default', true)->where('is_active', true)->doesntExist()) {
            $failures[] = 'No active default lead status — leads cannot be created (D-7); '.$reseed;
        }

        if (LeadStatus::query()->where('kind', LeadStatusKind::Converted->value)->doesntExist()) {
            $failures[] = 'No lead status of kind "converted" — leads cannot be converted (D-7); '.$reseed;
        }

        $pipeline = Pipeline::query()->where('is_default', true)->where('is_active', true)->first();

        if ($pipeline === null) {
            $failures[] = 'No active default pipeline — deals cannot be created (D-8); '.$reseed;
        } elseif (PipelineStage::query()->where('pipeline_id', $pipeline->getKey())->where('is_default', true)->doesntExist()) {
            $failures[] = 'The default pipeline has no default stage — deals cannot be created (D-8); fix its stages in Settings > Pipelines.';
        }

        $present = ActivityType::query()
            ->where('is_system', true)
            ->pluck('kind')
            ->map(static fn (mixed $kind): string => $kind instanceof ActivityKind ? $kind->value : (string) $kind)
            ->all();

        $missing = array_values(array_diff(array_map(static fn (ActivityKind $kind): string => $kind->value, ActivityKind::cases()), $present));

        if ($missing !== []) {
            $failures[] = sprintf(
                'System activity type%s missing for %s — task completion, conversion and email logging would fail (A-4); %s',
                count($missing) === 1 ? ' is' : 's are',
                implode(', ', $missing),
                $reseed,
            );
        }

        return $failures;
    }

    /**
     * The scheduler entry `scheduler:heartbeat` (routes/console.php) stamps the
     * cache every minute; a missing or stale stamp means the host's cron is
     * not running the scheduler, so nothing scheduled happens.
     */
    private function heartbeatWarning(): ?string
    {
        $cron = 'add the cron entry `* * * * * cd /path/to/app && <php> artisan schedule:run >> /dev/null 2>&1` on the host, with <php> the absolute path of the PHP 8.3 CLI binary (docs/DEPLOYMENT.md section 3.10, D-1)';

        try {
            $stamp = Cache::get(self::HEARTBEAT_KEY);
        } catch (Throwable $exception) {
            return 'The scheduler heartbeat cannot be read from the cache — '.$exception->getMessage();
        }

        if (! is_string($stamp) || $stamp === '') {
            return 'No scheduler heartbeat — `schedule:run` has not run in the last ten minutes: reminders, the queue drain and the retention prunes are not happening; '.$cron.'.';
        }

        try {
            $beat = Carbon::parse($stamp);
        } catch (Throwable) {
            return 'The scheduler heartbeat is unreadable ("'.$stamp.'"); '.$cron.'.';
        }

        if ($beat->lessThan(now()->subMinutes(self::HEARTBEAT_MAX_AGE_MINUTES))) {
            return sprintf(
                'The last scheduler heartbeat is %d minutes old (%s) — `schedule:run` is not running every minute; %s.',
                (int) floor($beat->diffInMinutes(now())),
                $beat->toIso8601String(),
                $cron,
            );
        }

        return null;
    }

    /** Compiled Blade templates exist (`php artisan view:cache` / `optimize`). */
    private function viewsAreCached(): bool
    {
        $compiled = (string) config('view.compiled');

        return $compiled !== '' && is_dir($compiled) && (glob(rtrim($compiled, '/\\').DIRECTORY_SEPARATOR.'*.php') ?: []) !== [];
    }

    /** The admin panel's component cache exists (`php artisan filament:optimize`). */
    private function filamentComponentsAreCached(): bool
    {
        try {
            return is_file(Filament::getPanel('admin')->getComponentCachePath());
        } catch (Throwable) {
            return false;
        }
    }

    private function isInside(string $path, string $directory): bool
    {
        $normalise = static function (string $value): string {
            $real = realpath($value);
            $value = str_replace('\\', '/', $real === false ? $value : $real);
            $value = rtrim($value, '/');

            return PHP_OS_FAMILY === 'Windows' ? strtolower($value) : $value;
        };

        $path = $normalise($path);
        $directory = $normalise($directory);

        return $path !== '' && ($path === $directory || str_starts_with($path.'/', $directory.'/'));
    }
}
