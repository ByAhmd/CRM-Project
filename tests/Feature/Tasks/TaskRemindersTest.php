<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\CrmRole;
use App\Enums\NotificationEvent;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\NotificationPreference;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskOverdueNotification;
use App\Notifications\TaskReminderNotification;
use App\Services\Tasks\TaskReminderService;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Reminders and overdue notices (decisions A-10, D-1, D-10): each open,
 * assigned task is reminded about once and reported overdue once, whatever
 * how often the scheduler runs; the notifications carry the task, open it
 * in the panel and travel by mail only when a real mailer is configured.
 */
final class TaskRemindersTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-09-06 09:00:00'));
    }

    #[Test]
    public function due_reminders_are_sent_once_per_task_and_the_stamp_makes_a_second_run_a_no_op(): void
    {
        $rep = $this->salesRep();
        $due = Task::factory()->create(['assignee_id' => $rep->getKey(), 'reminder_at' => Carbon::parse('2026-09-06 08:30:00')]);
        $future = Task::factory()->create(['assignee_id' => $rep->getKey(), 'reminder_at' => Carbon::parse('2026-09-06 10:00:00')]);
        $noReminder = Task::factory()->create(['assignee_id' => $rep->getKey(), 'reminder_at' => null]);
        $unassigned = Task::factory()->unassigned()->create(['reminder_at' => Carbon::parse('2026-09-06 08:00:00')]);
        $closed = Task::factory()->completed()->create(['assignee_id' => $rep->getKey(), 'reminder_at' => Carbon::parse('2026-09-06 08:00:00')]);
        $cancelled = Task::factory()->cancelled()->create(['assignee_id' => $rep->getKey(), 'reminder_at' => Carbon::parse('2026-09-06 08:00:00')]);

        $this->assertSame(1, $this->service()->sendDueReminders(now()));

        Notification::assertSentTo($rep, TaskReminderNotification::class, fn (TaskReminderNotification $notification): bool => $notification->body() === __('tasks.notifications.reminder_body', ['title' => $due->title, 'due' => $due->due_at?->format('Y-m-d H:i')]));
        Notification::assertSentToTimes($rep, TaskReminderNotification::class, 1);

        $this->assertSame('2026-09-06 09:00:00', $due->refresh()->reminder_sent_at?->format('Y-m-d H:i:s'));

        foreach ([$future, $noReminder, $unassigned, $closed, $cancelled] as $skipped) {
            $this->assertNull($skipped->refresh()->reminder_sent_at);
        }

        $this->assertSame(0, $this->service()->sendDueReminders(now()));

        Notification::assertSentToTimes($rep, TaskReminderNotification::class, 1);

        // Once the clock passes the second reminder it is sent, and only it.
        $this->travelTo(Carbon::parse('2026-09-06 10:30:00'));

        $this->assertSame(1, $this->service()->sendDueReminders(now()));
        $this->assertSame('2026-09-06 10:30:00', $future->refresh()->reminder_sent_at?->format('Y-m-d H:i:s'));

        Notification::assertSentToTimes($rep, TaskReminderNotification::class, 2);
    }

    #[Test]
    public function overdue_notices_are_sent_once_for_open_tasks_past_their_due_date(): void
    {
        $rep = $this->salesRep();
        $overdue = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => Carbon::parse('2026-09-05 17:00:00')]);
        $dueLater = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => Carbon::parse('2026-09-06 17:00:00')]);
        $undated = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => null]);
        $completedLate = Task::factory()->completed()->create(['assignee_id' => $rep->getKey(), 'due_at' => Carbon::parse('2026-09-01 09:00:00')]);
        $unassigned = Task::factory()->unassigned()->create(['due_at' => Carbon::parse('2026-09-01 09:00:00')]);

        $this->assertSame(1, $this->service()->notifyOverdue(now()));

        Notification::assertSentTo($rep, TaskOverdueNotification::class, fn (TaskOverdueNotification $notification): bool => str_contains($notification->body(), $overdue->title));
        Notification::assertSentToTimes($rep, TaskOverdueNotification::class, 1);

        $this->assertSame('2026-09-06 09:00:00', $overdue->refresh()->overdue_notified_at?->format('Y-m-d H:i:s'));

        foreach ([$dueLater, $undated, $completedLate, $unassigned] as $skipped) {
            $this->assertNull($skipped->refresh()->overdue_notified_at);
        }

        $this->assertSame(0, $this->service()->notifyOverdue(now()));

        Notification::assertSentToTimes($rep, TaskOverdueNotification::class, 1);
        Notification::assertNotSentTo($rep, TaskReminderNotification::class);
    }

    #[Test]
    public function the_notifications_carry_the_title_the_open_action_and_the_assignee_locale(): void
    {
        $rep = $this->makeUser(CrmRole::SalesRep, ['name' => 'English Rep', 'locale' => 'en']);
        $task = Task::factory()->create([
            'assignee_id' => $rep->getKey(),
            'title' => 'Renew the contract',
            'due_at' => Carbon::parse('2026-09-05 17:00:00'),
            'reminder_at' => Carbon::parse('2026-09-05 16:00:00'),
        ]);

        $this->service()->sendDueReminders(now());
        $this->service()->notifyOverdue(now());

        Notification::assertSentTo($rep, TaskReminderNotification::class, fn (TaskReminderNotification $notification): bool => $notification->locale === 'en');
        Notification::assertSentTo($rep, TaskOverdueNotification::class, fn (TaskOverdueNotification $notification): bool => $notification->locale === 'en');

        $url = TaskResource::getUrl('view', ['record' => $task]);

        foreach ([new TaskReminderNotification($task), new TaskOverdueNotification($task)] as $notification) {
            $database = $notification->toDatabase($rep);
            $encoded = (string) json_encode($database, JSON_UNESCAPED_SLASHES);

            $this->assertSame($notification->title(), $database['title'] ?? null);
            $this->assertStringContainsString('Renew the contract', $encoded);
            $this->assertStringContainsString('2026-09-05 17:00', $encoded);
            $this->assertStringContainsString($url, $encoded);
            $this->assertSame($url, $notification->url());

            $mail = $notification->toMail($rep);

            $this->assertSame($notification->title(), $mail->subject);
            $this->assertSame($url, $mail->actionUrl);
            $this->assertStringContainsString('Renew the contract', implode(' ', array_map(static fn (mixed $line): string => (string) $line, $mail->introLines)));
        }
    }

    #[Test]
    public function mail_travels_only_when_a_real_mailer_is_configured_and_the_assignee_opted_in(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey()]);

        config()->set('mail.default', 'log');

        $this->assertSame(['database'], (new TaskReminderNotification($task))->via($rep));
        $this->assertSame(['database'], (new TaskOverdueNotification($task))->via($rep));

        // A real transport alone is not enough: mail is opt-in per event (D-10).
        config()->set('mail.default', 'smtp');

        $this->assertSame(['database'], (new TaskReminderNotification($task))->via($rep));
        $this->assertSame(['database'], (new TaskOverdueNotification($task))->via($rep));

        NotificationPreference::factory()->ofEvent(NotificationEvent::TaskReminder)->withMail()->create(['user_id' => $rep->getKey()]);
        NotificationPreference::factory()->ofEvent(NotificationEvent::TaskOverdue)->withMail()->create(['user_id' => $rep->getKey()]);

        $this->assertSame(['database', 'mail'], (new TaskReminderNotification($task))->via($rep));
        $this->assertSame(['database', 'mail'], (new TaskOverdueNotification($task))->via($rep));

        // The opt-in is worthless without a transport.
        config()->set('mail.default', 'log');

        $this->assertSame(['database'], (new TaskReminderNotification($task))->via($rep));
        $this->assertSame(['database'], (new TaskOverdueNotification($task))->via($rep));
    }

    #[Test]
    public function the_scheduler_commands_run_the_passes_and_print_the_counts(): void
    {
        $rep = $this->salesRep();
        Task::factory()->create(['assignee_id' => $rep->getKey(), 'reminder_at' => Carbon::parse('2026-09-06 08:00:00'), 'due_at' => Carbon::parse('2026-09-05 12:00:00')]);
        Task::factory()->create(['assignee_id' => $rep->getKey(), 'reminder_at' => null, 'due_at' => Carbon::parse('2026-09-05 12:00:00')]);

        $this->artisan('tasks:send-reminders')
            ->expectsOutputToContain('1 reminder sent')
            ->assertSuccessful();

        $this->artisan('tasks:notify-overdue')
            ->expectsOutputToContain('2 overdue notifications sent')
            ->assertSuccessful();

        $this->artisan('tasks:send-reminders')
            ->expectsOutputToContain('0 reminders sent')
            ->assertSuccessful();

        $this->artisan('tasks:notify-overdue')
            ->expectsOutputToContain('0 overdue notifications sent')
            ->assertSuccessful();

        Notification::assertSentToTimes($rep, TaskReminderNotification::class, 1);
        Notification::assertSentToTimes($rep, TaskOverdueNotification::class, 2);
    }

    #[Test]
    public function a_task_whose_assignee_was_deleted_is_skipped(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'reminder_at' => Carbon::parse('2026-09-06 08:00:00')]);

        User::query()->whereKey($rep->getKey())->delete();

        $this->assertSame(0, $this->service()->sendDueReminders(now()));
        $this->assertNull($task->refresh()->reminder_sent_at);

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_failed_send_is_reported_stamped_and_does_not_stall_the_pass(): void
    {
        Exceptions::fake();

        $rep = $this->salesRep();
        $broken = Task::factory()->create(['assignee_id' => $rep->getKey(), 'title' => 'Broken mailbox', 'reminder_at' => Carbon::parse('2026-09-06 08:00:00')]);
        $fine = Task::factory()->create(['assignee_id' => $rep->getKey(), 'title' => 'Fine mailbox', 'reminder_at' => Carbon::parse('2026-09-06 08:00:00')]);

        $dispatcher = new class implements Dispatcher
        {
            /** @var list<string> */
            public array $delivered = [];

            public function send($notifiables, $notification): void
            {
                $this->sendNow($notifiables, $notification);
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                assert($notification instanceof TaskReminderNotification);

                if (str_contains($notification->body(), 'Broken mailbox')) {
                    throw new RuntimeException('SMTP connection refused');
                }

                $this->delivered[] = $notification->body();
            }
        };

        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->assertSame(2, $this->service()->sendDueReminders(now()));

        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'SMTP connection refused');
        Exceptions::assertReportedCount(1);

        $this->assertCount(1, $dispatcher->delivered);
        $this->assertStringContainsString('Fine mailbox', $dispatcher->delivered[0]);
        $this->assertNotNull($broken->refresh()->reminder_sent_at);
        $this->assertNotNull($fine->refresh()->reminder_sent_at);

        // The failed task is not retried on the next pass (its bell entry was written before mail).
        $this->assertSame(0, $this->service()->sendDueReminders(now()));
        Exceptions::assertReportedCount(1);
    }

    private function service(): TaskReminderService
    {
        return app(TaskReminderService::class);
    }
}
