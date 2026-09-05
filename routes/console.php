<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Queue drain — shared hosting has no persistent worker (D-1)
|--------------------------------------------------------------------------
|
| Notifications, reminders and imports are queued. The host cannot run a
| persistent queue:work process, so the scheduler drains the database queue
| every minute, stopping when empty and capped at 50 seconds so a busy minute
| rolls over instead of becoming the long-lived process the host kills.
|
| Gated on the queue driver not being sync (tests, or a misconfigured server):
| under sync there is nothing to drain. Reads config(), not env(), so
| config:cache is safe. withoutOverlapping(10) keeps a killed drain from
| silencing the entry for a day.
|
*/
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->when(fn (): bool => config('queue.default') !== 'sync')
    ->withoutOverlapping(10)
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Retention (D-13)
|--------------------------------------------------------------------------
*/
Schedule::command(sprintf('activitylog:clean --days=%d --force', (int) config('crm.audit.retention_days')))
    ->weekly()
    ->onOneServer();

Schedule::command('queue:prune-failed --hours=168')
    ->weekly()
    ->onOneServer();
