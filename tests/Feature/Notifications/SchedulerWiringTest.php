<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The scheduler entries the notification module depends on (decisions D-1,
 * D-13, plan section 3.6): the queue drain, the task passes, the stale-lead
 * pass, the upload prune and the audit retention — each pinned to one
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
