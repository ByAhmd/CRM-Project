<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

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
| Task reminders and overdue notices (module 9, A-10)
|--------------------------------------------------------------------------
|
| Both commands are idempotent (reminder_sent_at / overdue_notified_at are
| stamped by TaskReminderService), so a missed or doubled run never sends
| twice. Reminders fire within five minutes of reminder_at; overdue notices
| within fifteen minutes of due_at passing.
|
*/
Schedule::command('tasks:send-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('tasks:notify-overdue')
    ->everyFifteenMinutes()
    ->withoutOverlapping(5)
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Abandoned uploads
|--------------------------------------------------------------------------
|
| Filament parks uploads under tmp/{user id}/ on the attachments disk until
| the form is submitted; a closed form leaves the file behind. Anything older
| than a day there is no longer referenced by any open form and is removed.
|
*/
Schedule::call(function (): void {
    $disk = Storage::disk((string) config('crm.attachments.disk'));
    $cutoff = now()->subDay()->getTimestamp();

    foreach ($disk->allFiles('tmp') as $file) {
        if ($disk->lastModified($file) < $cutoff) {
            $disk->delete($file);
        }
    }
})->name('attachments:prune-temporary')->daily()->onOneServer();

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
