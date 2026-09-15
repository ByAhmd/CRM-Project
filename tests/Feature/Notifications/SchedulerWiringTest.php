<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\RescoreLeads;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The scheduler entries the notification module depends on (decisions D-1,
 * D-13, plan section 3.6): the queue drain, the scheduler heartbeat
 * app:preflight reads, the task passes, the stale-lead pass, the lead
 * rescore, the upload prunes (attachments and Livewire temporary uploads)
 * and the audit retention — each pinned to one
 * server, so a deploy cannot silently lose or double one.
 */
final class SchedulerWiringTest extends TestCase
{
    #[Test]
    public function the_queue_drain_runs_every_minute_on_one_server_without_overlapping(): void
    {
        $event = $this->commandEvent('queue:work --stop-when-empty --max-time=50');

        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
    }

    #[Test]
    public function the_scheduler_heartbeat_is_a_named_closure_every_minute_on_one_server(): void
    {
        $event = $this->callbackEvent('scheduler:heartbeat');

        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->evenInMaintenanceMode, 'the heartbeat must keep stamping while a deploy holds the site down');
    }

    #[Test]
    public function the_scheduler_heartbeat_stamps_the_time_app_preflight_reads(): void
    {
        $this->travelTo(now()->startOfMinute());
        Cache::forget('scheduler.heartbeat');

        $this->callbackEvent('scheduler:heartbeat')->run(app());

        $this->assertSame(now()->toIso8601String(), Cache::get('scheduler.heartbeat'));

        $this->travel(11)->minutes();
        $this->assertNull(Cache::get('scheduler.heartbeat'), 'the heartbeat outlives its ten minutes');
    }

    #[Test]
    public function the_task_passes_run_on_one_server_without_overlapping(): void
    {
        $reminders = $this->commandEvent('tasks:send-reminders');
        $overdue = $this->commandEvent('tasks:notify-overdue');

        $this->assertSame('*/5 * * * *', $reminders->expression);
        $this->assertTrue($reminders->onOneServer);
        $this->assertTrue($reminders->withoutOverlapping);

        $this->assertSame('*/15 * * * *', $overdue->expression);
        $this->assertTrue($overdue->onOneServer);
        $this->assertTrue($overdue->withoutOverlapping);
    }

    #[Test]
    public function the_stale_lead_pass_runs_daily_at_seven_in_the_app_timezone_on_one_server(): void
    {
        $event = $this->commandEvent('leads:notify-stale');

        $this->assertSame('0 7 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertSame('Asia/Riyadh', config('app.timezone'));
        $this->assertSame(config('app.timezone'), $event->timezone);
    }

    #[Test]
    public function the_upload_prune_is_a_named_daily_closure_on_one_server(): void
    {
        $event = null;

        foreach (app(Schedule::class)->events() as $candidate) {
            if ($candidate instanceof CallbackEvent && $candidate->description === 'attachments:prune-temporary') {
                $event = $candidate;
            }
        }

        $this->assertInstanceOf(CallbackEvent::class, $event, 'attachments:prune-temporary is not scheduled');
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
    }

    #[Test]
    public function the_lead_rescore_is_queued_daily_on_one_server(): void
    {
        $event = $this->callbackEvent(RescoreLeads::class);

        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
    }

    #[Test]
    public function the_upload_prune_removes_only_parked_files_older_than_a_day(): void
    {
        $disk = Storage::fake((string) config('crm.attachments.disk'));
        $disk->put('tmp/7/stale.pdf', 'stale');
        $disk->put('tmp/7/fresh.pdf', 'fresh');
        $disk->put('leads/1/kept.pdf', 'kept');
        touch($disk->path('tmp/7/stale.pdf'), now()->subDays(2)->getTimestamp());
        touch($disk->path('leads/1/kept.pdf'), now()->subDays(30)->getTimestamp());

        $this->callbackEvent('attachments:prune-temporary')->run(app());

        $disk->assertMissing('tmp/7/stale.pdf');
        $disk->assertExists('tmp/7/fresh.pdf');
        $disk->assertExists('leads/1/kept.pdf');
    }

    #[Test]
    public function the_livewire_upload_prune_is_a_named_daily_closure_on_one_server(): void
    {
        $event = $this->callbackEvent('uploads:prune-livewire-temporary');

        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
    }

    #[Test]
    public function the_livewire_upload_prune_removes_only_temporary_uploads_older_than_a_day(): void
    {
        // In tests Livewire resolves its own faked disk; the closure reads the same configuration.
        $disk = FileUploadConfiguration::storage();
        $directory = FileUploadConfiguration::path();
        $disk->put($directory.'/stale-import.csv', "name,email\nReal Person,real@example.com\n");
        $disk->put($directory.'/stale-import.csv.json', '{}');
        $disk->put($directory.'/fresh-import.csv', 'fresh');
        $disk->put('kept/older.csv', 'kept');
        touch($disk->path($directory.'/stale-import.csv'), now()->subDays(2)->getTimestamp());
        touch($disk->path($directory.'/stale-import.csv.json'), now()->subHours(25)->getTimestamp());
        touch($disk->path('kept/older.csv'), now()->subDays(30)->getTimestamp());

        $this->callbackEvent('uploads:prune-livewire-temporary')->run(app());

        $disk->assertMissing($directory.'/stale-import.csv');
        $disk->assertMissing($directory.'/stale-import.csv.json');
        $disk->assertExists($directory.'/fresh-import.csv');
        $disk->assertExists('kept/older.csv');
    }

    #[Test]
    public function the_livewire_upload_prune_follows_the_configured_directory(): void
    {
        config(['livewire.temporary_file_upload.directory' => 'uploads-in-flight']);

        $disk = FileUploadConfiguration::storage();
        $disk->put('uploads-in-flight/stale.xlsx', 'stale');
        $disk->put('livewire-tmp/untouched.xlsx', 'other');
        touch($disk->path('uploads-in-flight/stale.xlsx'), now()->subDays(2)->getTimestamp());
        touch($disk->path('livewire-tmp/untouched.xlsx'), now()->subDays(2)->getTimestamp());

        $this->callbackEvent('uploads:prune-livewire-temporary')->run(app());

        $disk->assertMissing('uploads-in-flight/stale.xlsx');
        $disk->assertExists('livewire-tmp/untouched.xlsx');
        $disk->delete('livewire-tmp/untouched.xlsx');
    }

    #[Test]
    public function the_audit_ledger_and_the_failed_jobs_are_pruned_weekly_on_one_server(): void
    {
        $clean = $this->commandEvent('activitylog:clean --days='.config('crm.audit.retention_days').' --force');
        $failed = $this->commandEvent('queue:prune-failed --hours=168');

        $this->assertSame('0 0 * * 0', $clean->expression);
        $this->assertTrue($clean->onOneServer);
        $this->assertSame('0 0 * * 0', $failed->expression);
        $this->assertTrue($failed->onOneServer);
    }

    #[Test]
    public function every_scheduled_command_is_registered_exactly_once(): void
    {
        $commands = [];

        foreach (app(Schedule::class)->events() as $event) {
            if (! $event instanceof CallbackEvent) {
                $commands[] = $this->commandString($event);
            }
        }

        $this->assertSame($commands, array_values(array_unique($commands)));
    }

    private function callbackEvent(string $description): CallbackEvent
    {
        foreach (app(Schedule::class)->events() as $event) {
            if ($event instanceof CallbackEvent && $event->description === $description) {
                return $event;
            }
        }

        $this->fail(sprintf('"%s" is not scheduled.', $description));
    }

    private function commandEvent(string $needle): Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (! $event instanceof CallbackEvent && str_contains($this->commandString($event), $needle)) {
                return $event;
            }
        }

        $this->fail(sprintf('"%s" is not scheduled.', $needle));
    }

    private function commandString(Event $event): string
    {
        // The command string embeds the (platform-quoted) php binary and artisan path; only the tail is stable.
        $command = (string) $event->command;

        return preg_match('/artisan[\'"]?\s+(.+)$/', $command, $match) === 1 ? $match[1] : $command;
    }
}
