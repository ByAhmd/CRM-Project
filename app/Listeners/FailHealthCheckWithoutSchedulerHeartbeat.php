<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Console\Commands\PreflightCommand;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Makes `/up` answer 500 in production when the scheduler has stopped (D-16).
 *
 * The scheduler entry `scheduler:heartbeat` (routes/console.php) stamps
 * {@see PreflightCommand::HEARTBEAT_KEY} every minute. When the stamp is
 * missing, unreadable or older than ten minutes, the host's cron is not running
 * `schedule:run`: no reminders, no queue drain, no backup, no prunes. Throwing
 * here turns Laravel's health route into a 500, so the external monitor
 * (`.github/workflows/uptime.yml`) covers "site down" and "scheduler stopped"
 * with one request. A cache that cannot be read at all propagates its own
 * exception, which fails the check the same way.
 *
 * Only production is checked: a local machine or the test suite has no cron,
 * and their `/up` stays a plain liveness answer. The messages go to the log
 * through the health route's report(), never to the response body.
 *
 * Registered by Laravel's listener discovery (app/Listeners).
 */
final class FailHealthCheckWithoutSchedulerHeartbeat
{
    /** The stamp is kept for ten minutes, so "missing" and "stale" share the limit (D-16). */
    public const MAX_AGE_MINUTES = 10;

    public function handle(DiagnosingHealth $event): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $stamp = Cache::get(PreflightCommand::HEARTBEAT_KEY);

        if (! is_string($stamp) || $stamp === '') {
            throw new RuntimeException('No scheduler heartbeat: schedule:run has not run in the last '.self::MAX_AGE_MINUTES.' minutes.');
        }

        try {
            $beat = Carbon::parse($stamp);
        } catch (Throwable $exception) {
            throw new RuntimeException('The scheduler heartbeat is unreadable.', previous: $exception);
        }

        if ($beat->lessThan(now()->subMinutes(self::MAX_AGE_MINUTES))) {
            throw new RuntimeException(sprintf(
                'The last scheduler heartbeat is %d minutes old (%s): schedule:run is not running every minute.',
                (int) floor($beat->diffInMinutes(now())),
                $beat->toIso8601String(),
            ));
        }
    }
}
