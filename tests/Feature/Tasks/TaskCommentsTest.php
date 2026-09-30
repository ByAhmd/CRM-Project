<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\NotificationEvent;
use App\Enums\TaskStatus;
use App\Enums\TaskUpdateKind;
use App\Enums\UserStatus;
use App\Exceptions\Tasks\InvalidTaskTransitionException;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\DealTasksRelationManager;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Deal;
use App\Models\NotificationPreference;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\Team;
use App\Models\User;
use App\Notifications\TaskCommentNotification;
use App\Notifications\TaskProgressNotification;
use App\Services\Settings\SettingsRepository;
use App\Services\Tasks\TaskService;
use App\Support\Notifications\NotificationChannels;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Text;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Activitylog\Models\Activity as AuditEntry;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Feature\QualityPass\Performance\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * Decision D-17 (owner, 2026-09-30): anyone who may view a task may comment
 * on it. Comments join the progress entries in the task's immutable thread
 * (task_updates.kind = comment), shown newest first and marked by kind; a
 * comment is required text of at most 2000 characters and is never edited or
 * deleted. Each comment tells the task's participants — assignee, assigner
 * (or creator) and earlier commenters — never its author, only those who may
 * sign in and open the task, after commit, bell by default and mail opt-in
 * (NotificationEvent::TaskComment). Commenting is not an edit: a handed-out
 * task stays read-only for its assignee (D-14 amendment).
 */
final class TaskCommentsTest extends TestCase
{
    use CountsQueries;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    private User $manager;

    private User $rep;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-09-30 09:00:00'));
        config()->set('mail.default', 'log');

        $this->team = $this->makeTeam();
        $this->admin = $this->admin();
        $this->manager = $this->salesManager($this->team);
        $this->rep = $this->salesRep($this->team);
        $this->tasks = app(TaskService::class);
    }

    // --- Who may comment ---

    #[Test]
    public function the_assignee_of_a_handed_out_task_may_comment_while_other_employees_may_not(): void
    {
        $employee = $this->employee($this->team);
        $colleague = $this->makeUser(CrmRole::Employee, ['name' => 'Other Employee'], $this->team);
        $task = $this->tasks->create(['title' => 'Count the stock', 'assignee_id' => $employee->getKey()], $this->admin);

        $this->assertTrue($employee->can('comment', $task), 'the handed-out assignee comments');
        $this->assertFalse($employee->can('update', $task), 'commenting is not an edit: the details stay the assigner\'s');
        $this->assertFalse($colleague->can('view', $task), 'precondition: an employee sees only their own tasks');
        $this->assertFalse($colleague->can('comment', $task), 'another employee may not comment on a task they may not open');
        $this->assertTrue($this->admin->can('comment', $task), 'the administrator comments');

        $this->actingAs($colleague)->get(TaskResource::getUrl('view', ['record' => $task]))->assertNotFound();
    }

    #[Test]
    public function a_sales_manager_comments_within_reach_and_not_beyond(): void
    {
        $inTeam = $this->handedOut();
        $outsider = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));
        $elsewhere = $this->tasks->create(['title' => 'Jeddah visit', 'assignee_id' => $outsider->getKey()], $this->admin);

        $this->assertTrue($this->manager->can('comment', $inTeam));
        $this->assertFalse($this->manager->can('comment', $elsewhere));
        $this->assertFalse($this->rep->can('comment', $elsewhere));
    }

    #[Test]
    public function the_comment_ability_follows_view_and_refuses_a_deleted_task(): void
    {
        $task = $this->handedOut();

        foreach ([$this->admin, $this->manager, $this->rep, $this->readOnly(), $this->support(), $this->employee()] as $user) {
            $this->assertSame($user->can('view', $task), $user->can('comment', $task), "{$user->name}: comment follows view");
        }

        $this->assertTrue($this->rep->can('comment', Task::class), 'the class-level answer is view_any');

        $task->delete();

        $this->assertFalse($this->admin->can('comment', $task), 'a deleted task is frozen (D-13)');
        $this->assertFalse($this->rep->can('comment', $task));
    }

    // --- The service: the entry, validation, immutability, the ledger ---

    #[Test]
    public function a_comment_joins_the_thread_on_an_open_or_a_closed_task_without_moving_its_status(): void
    {
        $task = $this->handedOut();

        $comment = $this->tasks->comment($task, $this->manager, "  Did they confirm the date?\nCall me after.  ");

        $this->assertSame(TaskUpdateKind::Comment, $comment->kind);
        $this->assertNull($comment->status);
        $this->assertSame("Did they confirm the date?\nCall me after.", $comment->body);
        $this->assertSame($this->manager->getKey(), (int) $comment->user_id);
        $this->assertSame('2026-09-30 09:00:00', $comment->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(TaskStatus::Pending, $task->status, 'the caller\'s instance is refreshed and the status did not move');

        $this->tasks->complete($task, $this->rep, 'Done');
        $late = $this->tasks->comment($task, $this->admin, 'Thanks, well done.');

        $this->assertSame(TaskUpdateKind::Comment, $late->kind);
        $this->assertSame(TaskStatus::Completed, $task->refresh()->status, 'a closed task takes comments and stays closed');
        $this->assertSame(
            [TaskUpdateKind::Comment, TaskUpdateKind::Progress, TaskUpdateKind::Comment],
            TaskUpdate::query()->where('task_id', $task->getKey())->orderBy('id')->get()->map(static fn (TaskUpdate $entry): TaskUpdateKind => $entry->kind)->all(),
        );
        $this->assertSame(TaskUpdateKind::Progress, TaskUpdate::query()->where('task_id', $task->getKey())->where('status', TaskStatus::Completed->value)->sole()->kind, 'progress entries keep their kind');
    }

    #[Test]
    public function a_comment_needs_text_within_the_limit(): void
    {
        $task = $this->handedOut();

        foreach ([
            '' => __('tasks.validation.comment_body_required'),
            "  \n  " => __('tasks.validation.comment_body_required'),
            str_repeat('a', TaskService::COMMENT_TEXT_MAX + 1) => __('tasks.validation.comment_body_too_long', ['max' => TaskService::COMMENT_TEXT_MAX]),
        ] as $body => $message) {
            try {
                $this->tasks->comment($task, $this->rep, (string) $body);
                $this->fail('an invalid comment was accepted');
            } catch (ValidationException $exception) {
                $this->assertSame([$message], $exception->errors()['comment']);
            }
        }

        $this->assertSame(0, TaskUpdate::query()->count());
        $this->assertSame(2000, TaskService::COMMENT_TEXT_MAX);

        $atLimit = str_repeat('b', TaskService::COMMENT_TEXT_MAX);
        $this->assertSame($atLimit, $this->tasks->comment($task, $this->rep, $atLimit)->body, 'the limit itself is accepted');
    }

    #[Test]
    public function a_deleted_task_takes_no_comment_until_it_is_restored(): void
    {
        $task = $this->handedOut();
        $task->delete();

        try {
            $this->tasks->comment($task, $this->admin, 'Anyone?');
            $this->fail('a deleted task took a comment');
        } catch (InvalidTaskTransitionException $exception) {
            $this->assertSame(__('tasks.validation.comment_trashed'), $exception->getMessage());
        }

        $this->assertSame(0, TaskUpdate::query()->count());

        $task->restore();
        $this->tasks->comment($task, $this->admin, 'Back again');

        $this->assertSame(1, TaskUpdate::query()->count());
    }

    #[Test]
    public function a_comment_is_never_edited_or_deleted(): void
    {
        $comment = $this->tasks->comment($this->handedOut(), $this->manager, 'First thought');

        try {
            $comment->update(['body' => 'Second thought']);
            $this->fail('a comment was edited');
        } catch (LogicException) {
            // expected
        }

        try {
            $comment->delete();
            $this->fail('a comment was deleted');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame('First thought', $comment->refresh()->body);
    }

    #[Test]
    public function the_kind_column_is_constrained_and_defaults_to_progress(): void
    {
        $task = $this->handedOut();

        DB::table('task_updates')->insert(['task_id' => $task->getKey(), 'body' => 'Imported', 'created_at' => now()]);
        $this->assertSame(TaskUpdateKind::Progress, TaskUpdate::query()->sole()->kind, 'rows written before D-17 are progress entries');

        $this->expectException(QueryException::class);

        DB::table('task_updates')->insert(['task_id' => $task->getKey(), 'kind' => 'note', 'created_at' => now()]);
    }

    #[Test]
    public function the_audit_ledger_records_who_commented_and_what(): void
    {
        $task = $this->handedOut(['title' => 'Visit the client']);

        $this->tasks->comment($task, $this->manager, 'Bring the brochure');

        $entry = AuditEntry::query()->where('description', ActivityLogEvent::TaskCommented->value)->sole();

        $this->assertSame($task->getKey(), (int) $entry->subject_id);
        $this->assertSame($this->manager->getKey(), (int) $entry->causer_id);
        $this->assertSame('Visit the client', $entry->properties['subject_label']);
        $this->assertSame('Bring the brochure', $entry->properties['comment']);

        app()->setLocale('ar');
        $this->assertSame('تعليق على مهمة', ActivityLogEvent::TaskCommented->getLabel());
        app()->setLocale('en');
        $this->assertSame('Comment on a task', ActivityLogEvent::TaskCommented->getLabel());
    }

    // --- Recipients ---

    #[Test]
    public function the_participants_are_told_and_never_the_author(): void
    {
        $task = $this->handedOut();

        Notification::fake();

        // The manager comments: the assignee and the assigner hear of it.
        $this->tasks->comment($task, $this->manager, 'How is it going?');

        Notification::assertSentTo($this->rep, TaskCommentNotification::class);
        Notification::assertSentTo($this->admin, TaskCommentNotification::class);
        Notification::assertNotSentTo($this->manager, TaskCommentNotification::class);

        // The assignee answers: the assigner and the earlier commenter hear of it.
        $this->tasks->comment($task, $this->rep, 'Halfway there');

        Notification::assertSentToTimes($this->manager, TaskCommentNotification::class, 1);
        Notification::assertSentToTimes($this->admin, TaskCommentNotification::class, 2);
        Notification::assertSentToTimes($this->rep, TaskCommentNotification::class, 1);
        // A comment is not a progress report.
        Notification::assertNotSentTo($this->admin, TaskProgressNotification::class);
    }

    #[Test]
    public function the_creator_stands_in_when_no_assigner_was_recorded(): void
    {
        $legacy = Task::factory()->create(['assignee_id' => $this->rep->getKey(), 'assigned_by' => null, 'created_by' => $this->manager->getKey()]);

        Notification::fake();

        $this->tasks->comment($legacy, $this->rep, 'Started on it');

        Notification::assertSentTo($this->manager, TaskCommentNotification::class);
        Notification::assertNotSentTo($this->rep, TaskCommentNotification::class);
    }

    #[Test]
    public function only_participants_who_may_sign_in_and_open_the_task_are_told(): void
    {
        $task = $this->handedOut();
        $this->tasks->comment($task, $this->manager, 'Keep me posted');

        // The earlier commenter moves to another team and may no longer open the task.
        $this->manager->update(['team_id' => $this->makeTeam('Jeddah Team', 'فريق جدة')->getKey()]);
        $this->assertFalse($this->manager->refresh()->can('view', $task), 'precondition: the manager lost sight of the task');

        // The assigner is disabled.
        $this->admin->update(['status' => UserStatus::Disabled]);

        Notification::fake();

        $this->tasks->comment($task, $this->rep, 'Anyone there?');

        Notification::assertNothingSent();
        $this->assertFalse($this->tasks->commentsReachSomeone($task, $this->rep));
    }

    #[Test]
    public function a_deleted_participant_is_not_told(): void
    {
        $task = $this->handedOut();
        $support = $this->support($this->team);
        $this->tasks->comment($task, $support, 'Noted');
        $support->delete();

        Notification::fake();

        $this->tasks->comment($task, $this->rep, 'Thanks');

        Notification::assertSentTo($this->admin, TaskCommentNotification::class);
        Notification::assertNotSentTo($support, TaskCommentNotification::class);
    }

    #[Test]
    public function nothing_leaves_when_the_surrounding_write_rolls_back(): void
    {
        $task = $this->handedOut();

        Notification::fake();

        try {
            DB::transaction(function () use ($task): void {
                $this->tasks->comment($task, $this->manager, 'Rolled back');
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rolled back', $exception->getMessage());
        }

        Notification::assertNothingSent();
        $this->assertSame(0, TaskUpdate::query()->count());
        $this->assertSame(0, AuditEntry::query()->where('description', ActivityLogEvent::TaskCommented->value)->count());
    }

    #[Test]
    public function the_bell_is_on_by_default_and_mail_joins_it_only_when_chosen(): void
    {
        $task = $this->handedOut();
        config()->set('mail.default', 'smtp');

        $this->assertTrue(NotificationEvent::TaskComment->databaseByDefault());
        $this->assertFalse(NotificationEvent::TaskComment->mailByDefault());
        $this->assertSame(['database'], NotificationChannels::for($this->admin, NotificationEvent::TaskComment), 'mail stays off until the user opts in');

        NotificationPreference::factory()
            ->ofEvent(NotificationEvent::TaskComment)
            ->withMail()
            ->create(['user_id' => $this->admin->getKey()]);

        Notification::fake();

        $this->tasks->comment($task, $this->rep, 'Contract drafted');

        Notification::assertSentTo(
            $this->admin,
            TaskCommentNotification::class,
            static fn (TaskCommentNotification $notification, array $channels): bool => $channels === ['database', 'mail'],
        );
        Notification::assertNotSentTo($this->manager, TaskCommentNotification::class);
    }

    #[Test]
    public function the_bell_is_written_at_once_while_the_mail_is_queued(): void
    {
        $task = $this->handedOut();
        $comment = $this->tasks->comment($task, $this->rep, 'Queued');
        $notification = new TaskCommentNotification($task, $this->rep, $comment);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame(['database' => 'sync'], $notification->viaConnections());
        $this->assertSame(1, $this->admin->notifications()->count(), 'the assigner\'s bell is written by the comment itself');
    }

    #[Test]
    public function the_notice_reads_in_both_locales_and_quotes_the_comment_as_plain_text(): void
    {
        $task = Task::factory()->create(['assignee_id' => $this->rep->getKey(), 'title' => 'Send the brochure']);
        $comment = TaskUpdate::query()->create(['task_id' => $task->getKey(), 'user_id' => $this->manager->getKey(), 'kind' => TaskUpdateKind::Comment, 'body' => 'Use the new price list']);
        $notice = new TaskCommentNotification($task, $this->manager, $comment);

        app()->setLocale('ar');
        $this->assertSame('تعليق جديد على المهمة «Send the brochure»', $notice->toMail($this->rep)->subject);
        $this->assertSame(['علّق Sales Manager على المهمة «Send the brochure»: Use the new price list'], $notice->toMail($this->rep)->introLines);

        app()->setLocale('en');
        $this->assertSame('New comment on "Send the brochure"', $notice->toMail($this->rep)->subject);
        $this->assertSame(['Sales Manager commented on "Send the brochure": Use the new price list'], $notice->toMail($this->rep)->introLines);

        $escaped = $this->handedOut(['title' => 'Plain <i>title</i>']);
        $this->tasks->comment($escaped, $this->rep, 'Use <b>bold</b> & "quotes"');

        $bell = $this->admin->notifications()->sole()->data;
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; &amp;', (string) $bell['body']);
        $this->assertStringNotContainsString('<b>', (string) $bell['body']);
        $this->assertStringContainsString('Plain &lt;i&gt;title&lt;/i&gt;', (string) $bell['title']);

        $long = new TaskCommentNotification($task, $this->manager, $this->tasks->comment($task, $this->manager, str_repeat('word ', 200)));
        $line = $long->toMail($this->rep)->introLines[0];

        $this->assertStringEndsWith('...', $line);
        $this->assertLessThan(TaskCommentNotification::COMMENT_LIMIT + 120, mb_strlen($line), 'the quoted comment is cut to the limit');
    }

    // --- The task page ---

    #[Test]
    public function the_thread_shows_progress_and_comments_together_newest_first_in_both_locales(): void
    {
        $task = $this->handedOut();
        $this->tasks->start($task, $this->rep, 'Calling them first');
        $this->travel(5)->minutes();
        $this->tasks->comment($task, $this->manager, 'Ask about the <b>discount</b>');
        $this->travel(5)->minutes();
        $this->tasks->postUpdate($task, $this->rep, 'They want five percent');

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $page = Livewire::actingAs($this->admin)->test(ViewTask::class, ['record' => $task->getKey()]);
            $page->assertOk()->assertDontSee(__('tasks.empty.thread'));
            $html = $page->html();

            $start = mb_strpos($html, e(__('tasks.sections.thread')));
            $this->assertNotFalse($start, "{$locale}: the thread section renders");

            // Only the thread itself, from its heading to the next section.
            $thread = mb_substr($html, $start, (int) mb_strpos($html, e(__('tasks.sections.schedule')), $start) - $start);

            $this->assertInOrder($thread, [
                e(TaskUpdateKind::Progress->getLabel()), 'Sales Rep', '2026-09-30 09:10', 'They want five percent',
                e(TaskUpdateKind::Comment->getLabel()), 'Sales Manager', '2026-09-30 09:05', e(__('tasks.fields.comment_body')), 'Ask about the &lt;b&gt;discount&lt;/b&gt;',
                e(TaskUpdateKind::Progress->getLabel()), 'Sales Rep', '2026-09-30 09:00', e(TaskStatus::InProgress->getLabel()), 'Calling them first',
            ], $locale);
            $this->assertStringNotContainsString('<b>discount</b>', $thread, 'the comment is plain text');
        }
    }

    #[Test]
    public function the_thread_shows_times_in_the_organisation_timezone(): void
    {
        $task = $this->handedOut();
        $this->tasks->comment($task, $this->manager, 'Morning check');

        app(SettingsRepository::class)->update([SettingsRepository::TIMEZONE => 'Asia/Dubai'], $this->admin);

        Livewire::actingAs($this->admin)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSee('2026-09-30 10:00')
            ->assertDontSee('2026-09-30 09:00');
    }

    #[Test]
    public function the_thread_costs_no_query_per_entry(): void
    {
        $task = $this->handedOut();
        $this->comments($task, 2);

        $render = fn () => Livewire::actingAs($this->admin)->test(ViewTask::class, ['record' => $task->getKey()])->assertOk();
        $render();

        $small = $this->queriesDuring($render);

        $this->comments($task, 12);

        $large = $this->queriesDuring($render);

        $this->assertLessThanOrEqual(count($small), count($large), sprintf("ViewTask: %d queries for 2 comments, %d for 14.\n%s", count($small), count($large), $this->topRepeated($large)));
    }

    #[Test]
    public function the_handed_out_assignee_comments_from_the_task_page(): void
    {
        $task = $this->handedOut();

        foreach (['' => 'required', str_repeat('x', TaskService::COMMENT_TEXT_MAX + 1) => 'max'] as $body => $rule) {
            Livewire::actingAs($this->rep)
                ->test(ViewTask::class, ['record' => $task->getKey()])
                ->callAction('comment', data: ['comment' => (string) $body])
                ->assertHasActionErrors(['comment' => $rule]);
        }

        Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertActionHidden('edit')
            ->callAction('comment', data: ['comment' => 'Which warehouse first?'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('tasks.notifications.comment_posted'))
            ->assertSee('Which warehouse first?');

        $this->assertSame(TaskUpdateKind::Comment, $task->updates()->sole()->kind);
        $this->assertSame(TaskStatus::Pending, $task->refresh()->status);
    }

    #[Test]
    public function the_comment_action_is_hidden_on_a_deleted_task(): void
    {
        $task = $this->handedOut();
        $task->delete();

        Livewire::actingAs($this->admin)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertActionHidden('comment');

        $task->restore();

        Livewire::actingAs($this->admin)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertActionVisible('comment');
    }

    #[Test]
    public function the_row_menus_offer_the_comment_action(): void
    {
        $deal = Deal::factory()->create(['owner_id' => $this->rep->getKey()]);
        $task = $this->handedOut(['deal_id' => $deal->getKey()]);

        Livewire::actingAs($this->rep)
            ->test(ListTasks::class)
            ->assertTableActionVisible('comment', $task)
            ->callTableAction('comment', $task, data: ['comment' => 'From the list'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('tasks.notifications.comment_posted'));

        Livewire::actingAs($this->manager)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->assertTableActionVisible('comment', $task)
            ->callTableAction('comment', $task, data: ['comment' => 'From the deal'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('tasks.notifications.comment_posted'));

        $this->assertSame(['From the deal', 'From the list'], $task->updates()->pluck('body')->all());
    }

    #[Test]
    public function the_comment_modal_promises_a_notice_only_when_someone_will_receive_it(): void
    {
        $handedOut = $this->handedOut();
        $own = $this->tasks->create(['title' => 'My own follow-up', 'assignee_id' => $this->rep->getKey()], $this->rep);

        $this->assertTrue($this->tasks->commentsReachSomeone($handedOut, $this->rep), 'the assigner hears the assignee');
        $this->assertTrue($this->tasks->commentsReachSomeone($handedOut, $this->admin), 'the assignee hears the assigner');
        $this->assertFalse($this->tasks->commentsReachSomeone($own, $this->rep), 'nobody else takes part in a personal to-do');
        $this->assertTrue($this->tasks->commentsReachSomeone($own, $this->manager), 'the rep hears the manager on their own to-do');

        // The modal renders lazily, so the field's helper (its below-content text) is read off the mounted schema.
        $helper = static fn (?string $expected): Closure => static function (Textarea $field) use ($expected): bool {
            $texts = array_values(array_filter(
                $field->getChildSchema(Textarea::BELOW_CONTENT_SCHEMA_KEY)?->getComponents() ?? [],
                static fn (mixed $component): bool => $component instanceof Text,
            ));

            return array_map(static fn (Text $text): string => (string) $text->getContent(), $texts) === ($expected === null ? [] : [$expected]);
        };

        Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $handedOut->getKey()])
            ->mountAction('comment')
            ->assertFormFieldExists('comment', $helper(__('tasks.helpers.comment_notifies')));

        Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $own->getKey()])
            ->mountAction('comment')
            ->assertFormFieldExists('comment', $helper(null));
    }

    /**
     * Each needle appears after the previous one.
     *
     * @param  list<string>  $needles
     */
    private function assertInOrder(string $haystack, array $needles, string $context): void
    {
        $offset = 0;

        foreach ($needles as $needle) {
            $position = mb_strpos($haystack, $needle, $offset);

            $this->assertNotFalse($position, "{$context}: \"{$needle}\" missing or out of order in the thread");
            $offset = $position + mb_strlen($needle);
        }
    }

    private function comments(Task $task, int $count): void
    {
        $authors = [$this->manager, $this->rep, $this->admin];

        for ($i = 0; $i < $count; $i++) {
            $this->tasks->comment($task, $authors[$i % 3], 'Comment '.$i);
        }
    }

    /**
     * A task the administrator handed to the rep.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function handedOut(array $attributes = []): Task
    {
        return $this->tasks->create(['title' => 'Visit the client', 'assignee_id' => $this->rep->getKey(), ...$attributes], $this->admin);
    }
}
