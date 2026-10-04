<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\NotificationEvent;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Filament\Pages\NotificationPreferences;
use App\Models\ActivityLog;
use App\Models\NotificationPreference;
use App\Models\Task;
use App\Models\TaskUpdateMention;
use App\Models\Team;
use App\Models\User;
use App\Notifications\TaskCommentNotification;
use App\Notifications\TaskMentionNotification;
use App\Services\Audit\ActivityLogPresenter;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Tasks\TaskService;
use App\Support\Notifications\NotificationChannels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Activitylog\Models\Activity as AuditEntry;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Decision D-20 (owner, 2026-10-04): "@mentions in comments, to pull a
 * colleague into a task thread". A comment may mention every user who may
 * sign in and holds `task.view_any`, except its author — whatever the
 * author's reach; any other id is dropped without complaint. Each mention is
 * stored with the comment (append-only), named in the comment's ledger entry,
 * and tells the colleague with a "mentioned you" notice — bell and mail by
 * default — instead of the general comment notice; colleagues mentioned
 * earlier hear about later comments like every participant. The preferences
 * page offers the new event to `task.view_any` holders (D-19).
 */
final class TaskMentionsTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    private User $rep;

    private User $employee;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-10-04 09:00:00'));
        config()->set('mail.default', 'log');

        $this->team = $this->makeTeam();
        $this->admin = $this->admin();
        $this->rep = $this->salesRep($this->team);
        $this->employee = $this->employee($this->makeTeam('Jeddah Team', 'فريق جدة'));
        $this->tasks = app(TaskService::class);
    }

    // --- Who may be mentioned ---

    #[Test]
    public function every_active_task_reader_but_the_author_is_mentionable_whatever_the_authors_reach(): void
    {
        $manager = $this->salesManager($this->makeTeam('Dammam Team', 'فريق الدمام'));
        $disabled = $this->makeUser(CrmRole::SalesRep, ['name' => 'Disabled Rep', 'status' => UserStatus::Disabled]);
        $pending = $this->makeUser(CrmRole::SalesRep, ['name' => 'Pending Rep', 'status' => UserStatus::Pending]);
        $deleted = $this->makeUser(CrmRole::SalesRep, ['name' => 'Deleted Rep']);
        $deleted->delete();
        $leadsOnly = $this->userWithPermissions(null, [Permission::LeadViewAny]);
        $direct = $this->userWithPermissions(null, [Permission::TaskViewAny]);

        $mentionable = $this->tasks->mentionableUsers($this->employee)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        $this->assertEqualsCanonicalizing(
            [$this->admin->getKey(), $this->rep->getKey(), $manager->getKey(), $direct->getKey()],
            $mentionable,
            'an employee reaches only their own tasks, yet may pull in anyone who may read tasks',
        );

        foreach ([$this->employee, $disabled, $pending, $deleted, $leadsOnly] as $excluded) {
            $this->assertNotContains($excluded->getKey(), $mentionable, "{$excluded->name} is not mentionable");
        }

        $this->assertSame(
            $this->tasks->mentionableUsers($this->employee)->pluck('name')->sort()->values()->all(),
            $this->tasks->mentionableUsers($this->employee)->pluck('name')->all(),
            'the list is ordered by name',
        );
    }

    // --- The stored mention ---

    #[Test]
    public function a_comment_stores_one_row_per_mentioned_colleague_in_the_comments_transaction(): void
    {
        $task = $this->ownTaskOf($this->employee);
        $manager = $this->salesManager($this->team);

        $comment = $this->tasks->comment($task, $this->employee, '@Sales Rep and @Sales Manager, please help', [
            (int) $this->rep->getKey(),
            (int) $manager->getKey(),
            (int) $this->rep->getKey(),
        ]);

        $rows = TaskUpdateMention::query()->orderBy('id')->get();

        $this->assertCount(2, $rows, 'a comment mentions a colleague once');
        $this->assertSame([$this->rep->getKey(), $manager->getKey()], $rows->pluck('user_id')->map(static fn (mixed $id): int => (int) $id)->all());
        $this->assertSame([$comment->getKey()], $rows->pluck('task_update_id')->unique()->map(static fn (mixed $id): int => (int) $id)->values()->all());
        $this->assertSame([$task->getKey()], $rows->pluck('task_id')->unique()->map(static fn (mixed $id): int => (int) $id)->values()->all());
        $this->assertSame('2026-10-04 09:00:00', $rows->first()?->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('@Sales Rep and @Sales Manager, please help', $comment->body, 'the text is stored as written');
    }

    #[Test]
    public function ids_that_may_not_be_mentioned_are_dropped_without_complaint(): void
    {
        $task = $this->ownTaskOf($this->employee);
        $disabled = $this->makeUser(CrmRole::SalesRep, ['name' => 'Disabled Rep', 'status' => UserStatus::Disabled]);
        $deleted = $this->makeUser(CrmRole::SalesRep, ['name' => 'Deleted Rep']);
        $deleted->delete();
        $leadsOnly = $this->userWithPermissions(null, [Permission::LeadViewAny]);

        Notification::fake();

        $comment = $this->tasks->comment($task, $this->employee, 'Nobody valid here', [
            (int) $disabled->getKey(),
            (int) $deleted->getKey(),
            (int) $leadsOnly->getKey(),
            (int) $this->employee->getKey(),
            999999,
            0,
            -3,
        ]);

        $this->assertSame('Nobody valid here', $comment->body);
        $this->assertSame(0, TaskUpdateMention::query()->count());
        Notification::assertNothingSent();

        foreach ([$disabled, $leadsOnly] as $outsider) {
            $this->assertFalse($outsider->can('view', $task), "{$outsider->name} gained nothing");
        }
    }

    #[Test]
    public function a_mention_is_never_edited_or_deleted(): void
    {
        $this->tasks->comment($this->ownTaskOf($this->employee), $this->employee, '@Sales Rep look', [(int) $this->rep->getKey()]);
        $mention = TaskUpdateMention::query()->sole();

        try {
            $mention->update(['user_id' => $this->admin->getKey()]);
            $this->fail('a mention was edited');
        } catch (LogicException) {
            // expected
        }

        try {
            $mention->delete();
            $this->fail('a mention was deleted');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame($this->rep->getKey(), (int) $mention->refresh()->user_id);
    }

    #[Test]
    public function a_mention_is_written_only_for_a_comment_and_only_with_that_comments_task(): void
    {
        $task = $this->ownTaskOf($this->employee);
        $other = $this->ownTaskOf($this->employee, 'Another task');
        $comment = $this->tasks->comment($task, $this->employee, 'A comment');
        $progress = $this->tasks->postUpdate($task, $this->employee, 'A progress note');

        $attempts = [
            'the denormalised task is not the comment\'s' => ['task_update_id' => $comment->getKey(), 'task_id' => $other->getKey()],
            'a progress entry holds no mentions' => ['task_update_id' => $progress->getKey(), 'task_id' => $task->getKey()],
            'there is no such entry' => ['task_update_id' => 999999, 'task_id' => $task->getKey()],
        ];

        foreach ($attempts as $case => $attributes) {
            try {
                TaskUpdateMention::query()->create([...$attributes, 'user_id' => $this->rep->getKey()]);
                $this->fail("a mention was written although {$case}");
            } catch (LogicException) {
                // expected
            }
        }

        $this->assertSame(0, TaskUpdateMention::query()->count());
        $this->assertFalse($this->rep->can('view', $other), 'no stray row opened another task');
    }

    #[Test]
    public function nothing_is_stored_or_sent_when_the_surrounding_write_rolls_back(): void
    {
        $task = $this->ownTaskOf($this->employee);

        Notification::fake();

        try {
            DB::transaction(function () use ($task): void {
                $this->tasks->comment($task, $this->employee, '@Sales Rep rolled back', [(int) $this->rep->getKey()]);
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rolled back', $exception->getMessage());
        }

        Notification::assertNothingSent();
        $this->assertSame(0, TaskUpdateMention::query()->count());
        $this->assertFalse($this->rep->can('view', $task), 'the rolled-back mention opened nothing');
    }

    #[Test]
    public function the_comments_ledger_entry_names_the_mentioned_colleagues(): void
    {
        $task = $this->ownTaskOf($this->employee, 'Order the paper');
        $manager = $this->salesManager($this->team);

        $this->tasks->comment($task, $this->employee, '@Sales Manager and @Sales Rep, which supplier?', [(int) $manager->getKey(), (int) $this->rep->getKey()]);
        $this->tasks->comment($task, $this->employee, 'No mention this time');

        $entries = AuditEntry::query()->where('description', ActivityLogEvent::TaskCommented->value)->orderBy('id')->get();

        $this->assertSame(['Sales Manager', 'Sales Rep'], $entries[0]->properties['mentioned']);
        $this->assertArrayNotHasKey('mentioned', $entries[1]->properties->all(), 'a comment without mentions records none');

        $log = ActivityLog::query()->whereKey($entries[0]->getKey())->sole();

        foreach (['en' => 'Mentioned', 'ar' => 'المُشار إليهم'] as $locale => $label) {
            app()->setLocale($locale);

            $row = collect(app(ActivityLogPresenter::class)->for($log)->detailRows())->firstWhere('label', $label);

            $this->assertNotNull($row, "{$locale}: the ledger names the mentioned colleagues");
            $this->assertStringContainsString('Sales Manager', $row['value']);
            $this->assertStringContainsString('Sales Rep', $row['value']);
        }
    }

    // --- Who is told ---

    #[Test]
    public function the_mentioned_colleague_gets_the_mention_notice_instead_of_the_comment_notice(): void
    {
        $task = $this->tasks->create(['title' => 'Count the stock', 'assignee_id' => $this->employee->getKey()], $this->admin);

        Notification::fake();

        $this->tasks->comment($task, $this->employee, '@Sales Rep can you lend a hand?', [(int) $this->rep->getKey()]);

        Notification::assertSentTo($this->rep, TaskMentionNotification::class);
        Notification::assertNotSentTo($this->rep, TaskCommentNotification::class);
        // The assigner hears of the comment as before, and nobody hears twice.
        Notification::assertSentTo($this->admin, TaskCommentNotification::class);
        Notification::assertNotSentTo($this->admin, TaskMentionNotification::class);
        Notification::assertNotSentTo($this->employee, TaskCommentNotification::class);
        Notification::assertNotSentTo($this->employee, TaskMentionNotification::class);
    }

    #[Test]
    public function a_participant_who_is_also_mentioned_is_told_once_by_the_mention_notice(): void
    {
        $task = $this->tasks->create(['title' => 'Count the stock', 'assignee_id' => $this->employee->getKey()], $this->admin);

        Notification::fake();

        // The assigner mentions the assignee, who would have heard of the comment anyway.
        $this->tasks->comment($task, $this->admin, '@Employee User start with aisle 3', [(int) $this->employee->getKey(), (int) $this->employee->getKey()]);

        Notification::assertSentToTimes($this->employee, TaskMentionNotification::class, 1);
        Notification::assertNotSentTo($this->employee, TaskCommentNotification::class);
        Notification::assertNotSentTo($this->admin, TaskMentionNotification::class);
    }

    #[Test]
    public function a_colleague_mentioned_earlier_hears_about_later_comments_as_a_participant(): void
    {
        $task = $this->ownTaskOf($this->employee);
        $this->tasks->comment($task, $this->employee, '@Sales Rep a question for you', [(int) $this->rep->getKey()]);

        Notification::fake();

        $this->tasks->comment($task, $this->employee, 'Following up');

        Notification::assertSentTo($this->rep, TaskCommentNotification::class);
        Notification::assertNotSentTo($this->rep, TaskMentionNotification::class);
        $this->assertTrue($this->tasks->commentsReachSomeone($task, $this->employee), 'the comment modal promises a notice');

        // The participant answers: the author of the task hears of it.
        $this->tasks->comment($task, $this->rep, 'Here is the answer');

        Notification::assertSentTo($this->employee, TaskCommentNotification::class);
    }

    #[Test]
    public function a_mentioned_colleague_who_may_no_longer_sign_in_hears_nothing_more(): void
    {
        $task = $this->ownTaskOf($this->employee);
        $this->tasks->comment($task, $this->employee, '@Sales Rep look', [(int) $this->rep->getKey()]);
        $this->rep->update(['status' => UserStatus::Disabled]);

        Notification::fake();

        $this->tasks->comment($task, $this->employee, 'Anyone?');

        Notification::assertNothingSent();
    }

    #[Test]
    public function the_mention_notice_is_bell_and_mail_by_default_and_mail_can_be_switched_off(): void
    {
        $this->assertTrue(NotificationEvent::TaskMention->databaseByDefault());
        $this->assertTrue(NotificationEvent::TaskMention->mailByDefault(), 'a mention is a direct call, like an assignment');

        $this->assertSame(['database'], NotificationChannels::for($this->rep, NotificationEvent::TaskMention), 'no mail under the log mailer (D-10)');

        config()->set('mail.default', 'smtp');
        $this->assertSame(['database', 'mail'], NotificationChannels::for($this->rep, NotificationEvent::TaskMention));

        Notification::fake();

        $this->tasks->comment($this->ownTaskOf($this->employee), $this->employee, '@Sales Rep mail me', [(int) $this->rep->getKey()]);

        Notification::assertSentTo(
            $this->rep,
            TaskMentionNotification::class,
            static fn (TaskMentionNotification $notification, array $channels): bool => $channels === ['database', 'mail'],
        );

        NotificationPreference::factory()->ofEvent(NotificationEvent::TaskMention)->create(['user_id' => $this->rep->getKey(), 'mail' => false]);

        $this->assertSame(['database'], NotificationChannels::for($this->rep, NotificationEvent::TaskMention), 'the per-user opt-out holds');
    }

    #[Test]
    public function the_bell_is_written_at_once_while_the_mail_is_queued(): void
    {
        $task = $this->ownTaskOf($this->employee);
        $comment = $this->tasks->comment($task, $this->employee, '@Sales Rep queued', [(int) $this->rep->getKey()]);
        $notification = new TaskMentionNotification($task, $this->employee, $comment);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame(['database' => 'sync'], $notification->viaConnections());
        $this->assertSame(1, $this->rep->notifications()->count(), 'the bell is written by the comment itself');
    }

    #[Test]
    public function the_notice_reads_in_the_recipients_locale_quotes_the_comment_and_escapes_the_bell(): void
    {
        $task = $this->ownTaskOf($this->employee, 'Send the <i>brochure</i>');
        $this->rep->update(['locale' => 'ar']);

        $comment = $this->tasks->comment($task, $this->employee, '@Sales Rep use the <b>new</b> price list', [(int) $this->rep->getKey()]);
        $notice = new TaskMentionNotification($task, $this->employee, $comment);

        app()->setLocale('ar');
        $this->assertSame('أشار إليك Employee User في المهمة «Send the <i>brochure</i>»', $notice->toMail($this->rep)->subject);
        $this->assertSame(['أشار إليك Employee User في تعليق على المهمة «Send the <i>brochure</i>»: @Sales Rep use the <b>new</b> price list'], $notice->toMail($this->rep)->introLines);

        app()->setLocale('en');
        $this->assertSame('Employee User mentioned you on "Send the <i>brochure</i>"', $notice->toMail($this->rep)->subject);
        $this->assertSame(['Employee User mentioned you in a comment on "Send the <i>brochure</i>": @Sales Rep use the <b>new</b> price list'], $notice->toMail($this->rep)->introLines);

        // The bell was written in the recipient's language, escaped.
        $bell = $this->rep->notifications()->sole()->data;
        $this->assertStringStartsWith('أشار إليك', (string) $bell['title']);
        $this->assertStringContainsString('&lt;b&gt;new&lt;/b&gt;', (string) $bell['body']);
        $this->assertStringNotContainsString('<b>', (string) $bell['body']);
        $this->assertStringContainsString('Send the &lt;i&gt;brochure&lt;/i&gt;', (string) $bell['title']);

        $long = new TaskMentionNotification($task, $this->employee, $this->tasks->comment($task, $this->employee, str_repeat('word ', 200)));
        $line = $long->toMail($this->rep)->introLines[0];

        $this->assertStringEndsWith('...', $line);
        $this->assertLessThan(TaskMentionNotification::COMMENT_LIMIT + 160, mb_strlen($line), 'the quoted comment is cut to the limit');
    }

    // --- Preferences (D-19) and the database constraint ---

    #[Test]
    public function every_task_reader_is_offered_the_mention_event_in_the_tasks_section(): void
    {
        $service = app(NotificationPreferenceService::class);

        foreach (CrmRole::cases() as $role) {
            $user = $this->makeUser($role, ['name' => 'Role '.$role->value]);

            $this->assertTrue($service->offers($user, NotificationEvent::TaskMention), "{$role->value} holds task.view_any and is offered mentions");
        }

        $this->assertFalse($service->offers($this->userWithPermissions(null, [Permission::LeadViewAny, Permission::NoteCreate]), NotificationEvent::TaskMention));
        $this->assertSame([NotificationEvent::TaskMention], array_values(array_filter(
            $service->offeredEvents($this->userWithPermissions(null, [Permission::TaskViewAny])),
            static fn (NotificationEvent $event): bool => $event === NotificationEvent::TaskMention,
        )));
        $this->assertContains(NotificationEvent::TaskMention, NotificationPreferences::groups()['tasks']);
        $this->assertSame([Permission::TaskViewAny], $service->requiredPermissions(NotificationEvent::TaskMention));

        foreach (['en' => 'A colleague mentions me in a task comment', 'ar' => 'إشارة زميل إليّ في تعليق على مهمة'] as $locale => $label) {
            app()->setLocale($locale);
            $this->assertSame($label, NotificationEvent::TaskMention->getLabel());
        }
    }

    #[Test]
    public function the_event_check_accepts_the_mention_event(): void
    {
        DB::table('notification_preferences')->insert([
            'user_id' => $this->rep->getKey(),
            'event' => NotificationEvent::TaskMention->value,
            'database' => true,
            'mail' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(NotificationEvent::TaskMention, NotificationPreference::query()->sole()->event);

        $this->expectException(QueryException::class);

        DB::table('notification_preferences')->insert([
            'user_id' => $this->admin->getKey(),
            'event' => 'task_mentioned',
            'database' => true,
            'mail' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A personal to-do the user created for themselves. */
    private function ownTaskOf(User $user, string $title = 'Prepare the stock count'): Task
    {
        return $this->tasks->create(['title' => $title, 'assignee_id' => $user->getKey()], $user);
    }
}
