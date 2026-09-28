<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\NotificationEvent;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Models\NotificationPreference;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCompletedNotification;
use App\Services\Tasks\TaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Decision D-14 (2026-09-21): completing a task tells the person who handed
 * it out — the assigner, or the creator when no assigner was ever recorded —
 * on the bell, plus mail when the recipient opted in and a real transport
 * exists (D-10). The completer never notifies themselves, an account that
 * may no longer sign in receives nothing (D-11), and — as on every other
 * event path (A-20) — neither does a recipient who may not open the task.
 * The notice leaves after the completion commits and its mail through the
 * queue, so a mail server can never undo a completion.
 */
final class TaskCompletedNotificationTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        config()->set('mail.default', 'log');
    }

    #[Test]
    public function completing_notifies_the_assigner_on_the_bell(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $task = app(TaskService::class)->create(['title' => 'Chase the invoice', 'assignee_id' => $rep->getKey()], $admin);

        Notification::fake();

        app(TaskService::class)->complete($task, $rep, 'Paid in full');

        Notification::assertSentTo(
            $admin,
            TaskCompletedNotification::class,
            static fn (TaskCompletedNotification $notification, array $channels): bool => $channels === ['database'],
        );
        Notification::assertNotSentTo($rep, TaskCompletedNotification::class);
    }

    #[Test]
    public function the_creator_is_told_when_no_assigner_was_recorded(): void
    {
        // A pre-D-14 task: assigned_by was backfilled as NULL. The creator is
        // a manager whose team scope reaches the assignee, so the fallback
        // notice may reach them (A-20).
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $task = Task::factory()->create([
            'assignee_id' => $rep->getKey(),
            'assigned_by' => null,
            'created_by' => $manager->getKey(),
        ]);

        Notification::fake();

        app(TaskService::class)->complete($task, $rep);

        Notification::assertSentTo($manager, TaskCompletedNotification::class);
    }

    #[Test]
    public function a_recipient_who_may_not_open_the_task_is_skipped(): void
    {
        // The creator-fallback lands on a manager whose team scope no longer
        // reaches the assignee (different teams, no linked record). The
        // notification carries the task's title and a view link, so it never
        // reaches someone outside the task's visibility (A-20).
        $manager = $this->salesManager($this->makeTeam());
        $rep = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));
        $task = Task::factory()->create([
            'assignee_id' => $rep->getKey(),
            'assigned_by' => null,
            'created_by' => $manager->getKey(),
        ]);

        $this->assertFalse($manager->can('view', $task), 'precondition: the manager may not open the task');

        Notification::fake();

        app(TaskService::class)->complete($task, $rep);

        Notification::assertNothingSent();
    }

    #[Test]
    public function the_completer_never_notifies_themselves(): void
    {
        $rep = $this->salesRep();
        $task = app(TaskService::class)->create(['title' => 'My own follow-up', 'assignee_id' => $rep->getKey()], $rep);

        $this->assertSame($rep->getKey(), (int) $task->assigned_by, 'precondition: the rep is their own assigner');

        Notification::fake();

        app(TaskService::class)->complete($task, $rep);

        Notification::assertNothingSent();
    }

    #[Test]
    public function nobody_is_told_when_both_the_assigner_and_the_creator_are_gone(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create([
            'assignee_id' => $rep->getKey(),
            'assigned_by' => null,
            'created_by' => null,
        ]);

        Notification::fake();

        app(TaskService::class)->complete($task, $rep);

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_deleted_assigner_is_not_replaced_by_the_creator(): void
    {
        // The creator stands in only when no assigner was ever recorded. An
        // assigner who has since been deleted is treated like one who was
        // disabled: nobody is told, rather than someone else.
        $creator = $this->admin();
        $assigner = $this->admin();
        $rep = $this->salesRep();
        $task = Task::factory()->create([
            'assignee_id' => $rep->getKey(),
            'assigned_by' => $assigner->getKey(),
            'created_by' => $creator->getKey(),
        ]);

        $assigner->delete();
        $this->assertSame($assigner->getKey(), (int) $task->refresh()->assigned_by, 'precondition: a soft delete keeps the recorded assigner');

        Notification::fake();

        app(TaskService::class)->complete($task, $rep);

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_failing_mail_server_cannot_undo_the_completion(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $task = app(TaskService::class)->create(['title' => 'Close the quarter', 'assignee_id' => $rep->getKey()], $admin);

        NotificationPreference::factory()
            ->ofEvent(NotificationEvent::TaskCompleted)
            ->withMail()
            ->create(['user_id' => $admin->getKey()]);
        config()->set('mail.default', 'smtp');

        // On the host the mail is a queued job and a dead server fails only
        // that job. Tests run the queue synchronously, so the failure still
        // surfaces here — but after the commit.
        Event::listen(NotificationSending::class, static function (NotificationSending $event): void {
            if ($event->channel === 'mail') {
                throw new RuntimeException('The mail server is down.');
            }
        });

        try {
            app(TaskService::class)->complete($task, $rep, 'Signed off');
            $this->fail('the mail failure should surface from the synchronous test queue');
        } catch (RuntimeException $exception) {
            $this->assertSame('The mail server is down.', $exception->getMessage());
        }

        $fresh = $task->refresh();
        $this->assertSame(TaskStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskCompleted->value, 'subject_id' => $task->getKey()]);
        $this->assertSame(1, $admin->notifications()->where('type', TaskCompletedNotification::class)->count(), 'the bell is written before, and independently of, the mail');
    }

    #[Test]
    public function the_completion_bell_is_written_at_once_while_its_mail_is_queued(): void
    {
        $notification = new TaskCompletedNotification(Task::factory()->create(), $this->salesRep());

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame(['database' => 'sync'], $notification->viaConnections());
    }

    #[Test]
    public function a_recipient_who_may_no_longer_sign_in_is_skipped(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $task = app(TaskService::class)->create(['title' => 'Before the leave', 'assignee_id' => $rep->getKey()], $admin);

        $admin->update(['status' => UserStatus::Disabled]);

        Notification::fake();

        app(TaskService::class)->complete($task, $rep);

        Notification::assertNothingSent();
    }

    #[Test]
    public function mail_joins_the_bell_when_the_recipient_opted_in_and_a_real_transport_exists(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $task = app(TaskService::class)->create(['title' => 'Send the contract', 'assignee_id' => $rep->getKey()], $admin);

        NotificationPreference::factory()
            ->ofEvent(NotificationEvent::TaskCompleted)
            ->withMail()
            ->create(['user_id' => $admin->getKey()]);
        config()->set('mail.default', 'smtp');

        Notification::fake();

        app(TaskService::class)->complete($task, $rep);

        Notification::assertSentTo(
            $admin,
            TaskCompletedNotification::class,
            static fn (TaskCompletedNotification $notification, array $channels): bool => $channels === ['database', 'mail'],
        );
    }

    #[Test]
    public function the_strings_resolve_in_both_locales(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'title' => 'Send the brochure']);
        $recipient = User::factory()->create(['name' => 'Recipient']);
        $notification = new TaskCompletedNotification($task, $rep);

        app()->setLocale('ar');
        $arabic = $notification->toMail($recipient);
        $this->assertSame('اكتملت المهمة «Send the brochure»', $arabic->subject);
        $this->assertSame(['أكمل Sales Rep المهمة «Send the brochure».'], $arabic->introLines);

        app()->setLocale('en');
        $english = $notification->toMail($recipient);
        $this->assertSame('Task "Send the brochure" completed', $english->subject);
        $this->assertSame(['Sales Rep completed the task "Send the brochure".'], $english->introLines);
    }
}
