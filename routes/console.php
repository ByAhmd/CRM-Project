<?php

declare(strict_types=1);

use App\Filament\Exports\Reports\ReportRowExporter;
use App\Filament\Support\ImportExportActions;
use App\Jobs\RescoreLeads;
use App\Models\Export;
use App\Models\Import;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

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
| Scheduler heartbeat (D-1, app:preflight)
|--------------------------------------------------------------------------
|
| Everything above and below relies on the host's cron running
| `php artisan schedule:run` every minute. Nothing fails loudly when that
| cron line is missing or broken — reminders, the queue drain and the
| retention prunes simply never happen — so every run stamps the time under
| the `scheduler.heartbeat` cache key (kept 10 minutes). app:preflight warns
| when the stamp is missing or older than five minutes. It runs even in
| maintenance mode (it only writes the cache), so a deploy that keeps the site
| down for a few minutes does not make preflight blame a missing cron line.
|
*/
Schedule::call(function (): void {
    Cache::put('scheduler.heartbeat', now()->toIso8601String(), now()->addMinutes(10));
})->name('scheduler:heartbeat')->everyMinute()->evenInMaintenanceMode()->onOneServer();

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
| Stale leads (module 19, plan section 3.6)
|--------------------------------------------------------------------------
|
| Once a day, at the start of the working morning in the app timezone, the
| owner of every open lead that has had no activity for crm.leads.stale_days
| is told once. Idempotent (stale_notified_at is stamped by LeadStaleService
| and cleared again by the next activity), so a doubled run never repeats.
|
*/
Schedule::command('leads:notify-stale')
    ->dailyAt('07:00')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Lead scores (D-7)
|--------------------------------------------------------------------------
|
| A stored score is recomputed when the lead or a scoring rule is saved;
| nothing else moves it as time passes, so an activity-recency rule would
| keep its points long after the window closed. Once a day, before the
| working morning, every open lead is rescored. RescoreLeads is unique and
| works in batches that each fit the queue drain, so a slow night never
| stacks runs.
|
*/
Schedule::job(new RescoreLeads)
    ->dailyAt('03:00')
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
| Livewire temporary uploads (module 18, D-13)
|--------------------------------------------------------------------------
|
| Every file picked in a panel form — the CSV or XLSX of an import above
| all — is first stored by Livewire under its temporary upload directory
| (config livewire.temporary_file_upload.directory, `livewire-tmp` by
| default) on its upload disk (the default disk, storage/app/private). An
| import reads the file but never deletes it, and Livewire only clears
| files older than a day when the next upload starts, so on a quiet
| installation import files full of personal data would stay there, and in
| every backup of the private disk, indefinitely. Anything older than a
| day is no longer part of an open form and is removed, with Livewire's own
| path and disk resolution. Daily, idempotent; skipped on S3, where Livewire
| delegates the clean-up to a bucket lifecycle rule.
|
*/
Schedule::call(function (): void {
    if (FileUploadConfiguration::isUsingS3()) {
        return;
    }

    $disk = FileUploadConfiguration::storage();
    $cutoff = now()->subDay()->getTimestamp();

    foreach ($disk->allFiles(FileUploadConfiguration::path()) as $file) {
        // A concurrent upload request may have cleared the file already.
        if ($disk->exists($file) && $disk->lastModified($file) < $cutoff) {
            $disk->delete($file);
        }
    }
})->name('uploads:prune-livewire-temporary')->daily()->onOneServer();

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

/*
|--------------------------------------------------------------------------
| Import / export history (module 18, D-13)
|--------------------------------------------------------------------------
|
| Filament's Import and Export models carry Prunable without a window, so
| the application models define one (Import::RETENTION_DAYS,
| Export::RETENTION_DAYS) and Export removes its files from the private
| disk before the row goes (pruning hook). Failed import rows are never
| pruned on their own: Filament's FailedImportRow window (one month) is
| shorter than the import retention, and a run whose failed rows had gone
| early would still advertise them on its view page with an empty list and
| a header-only download. They leave with their import through the
| cascading foreign key. Daily, idempotent.
|
*/
Schedule::command('model:prune', [
    '--model' => [Import::class, Export::class],
])
    ->daily()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Report downloads (module 23, D-1)
|--------------------------------------------------------------------------
|
| A report export is written to the private disk and unlinked as soon as it
| has been streamed. A client that aborts mid-transfer, or a process the
| host kills, can still strand the file; nothing else references the
| directory, so anything older than an hour there is gone.
|
*/
Schedule::call(function (): void {
    $disk = Storage::disk(ImportExportActions::FILE_DISK);
    $cutoff = now()->subHour()->getTimestamp();

    foreach ($disk->files(ReportRowExporter::DIRECTORY) as $file) {
        if ($disk->lastModified($file) < $cutoff) {
            $disk->delete($file);
        }
    }
})->name('reports:prune-downloads')->hourly()->onOneServer();
