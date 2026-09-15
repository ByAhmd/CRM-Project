<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActivityKind;
use App\Enums\LeadStatusKind;
use App\Models\ActivityType;
use App\Models\LeadStatus;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * Production preflight (docs/ARCHITECTURE_PLAN.md section 9).
 *
 * Run by the deploy script while the site is still in maintenance mode, so a
 * dangerous configuration fails the deploy instead of going live.
 *
 * FAILURES (exit 1): settings that are actively dangerous the moment the site
 * is up (the configuration checks are enforced only when APP_ENV is
 * production), and — in every environment — a database the CRM cannot run
 * on: unreachable, with pending migrations, or without the reference rows the
 * workflows depend on (a default lead status and the Converted status, a
 * default pipeline with a default stage, one system activity type per
 * ActivityKind). Those rows come from the reference seed that app:onboard and
 * `db:seed --force` run. WARNINGS (exit 0): degraded-but-operable states the
 * operator should know about — D-10 allows the log mailer until production
 * SMTP exists.
 *
 * Operator-facing CLI output is plain English, read by engineers in deploy
 * logs, like app:onboard.
 */
final class PreflightCommand extends Command
{
    protected $signature = 'app:preflight';

    protected $description = 'Verify production configuration and reference data before the site leaves maintenance mode';

    public function handle(): int
    {
        $production = $this->laravel->environment('production');
        $failures = [];
        $warnings = [];

        if (blank(config('app.key'))) {
            $failures[] = 'APP_KEY is not set — encrypted casts and sessions cannot work.';
        }

        if ($production && config('app.debug') === true) {
            $failures[] = 'APP_DEBUG is true in production — stack traces and configuration would be exposed.';
        }

        if ($production && ! str_starts_with((string) config('app.url'), 'https://')) {
            $failures[] = 'APP_URL is not https in production — signed URLs and cookies would be issued for plain HTTP.';
        }

        if ($production && config('session.secure') !== true) {
            $failures[] = 'SESSION_SECURE_COOKIE is not true — session cookies may travel over plain HTTP (D-11).';
        }

        if ($production && config('queue.default') === 'sync') {
            $failures[] = 'QUEUE_CONNECTION is sync — notifications, reminders and imports would run inside requests.';
        }

        array_push($failures, ...$this->databaseFailures());

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $warnings[] = sprintf(
                'MAIL_MAILER is "%s" — invitations, password resets and mail notifications are not delivered (D-10).',
                (string) config('mail.default'),
            );
        }

        if ($production && config('cache.default') === 'array') {
            $warnings[] = 'CACHE_STORE is array — settings and permissions are re-read on every request.';
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

        return $this->referenceDataFailures();
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
}
