<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Production preflight (docs/ARCHITECTURE_PLAN.md section 9).
 *
 * Run by the deploy script while the site is still in maintenance mode, so a
 * dangerous configuration fails the deploy instead of going live.
 *
 * FAILURES (exit 1; enforced only when APP_ENV is production): settings that
 * are actively dangerous the moment the site is up. WARNINGS (exit 0):
 * degraded-but-operable states the operator should know about — D-10 allows
 * the log mailer until production SMTP exists.
 */
final class PreflightCommand extends Command
{
    protected $signature = 'app:preflight';

    protected $description = 'Verify production configuration before the site leaves maintenance mode';

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
}
