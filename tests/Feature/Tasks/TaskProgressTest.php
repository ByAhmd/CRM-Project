<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\NotificationEvent;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Exceptions\Tasks\InvalidTaskTransitionException;
use App\Filament\Pages\TasksBoard;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Models\NotificationPreference;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Notifications\TaskCompletedNotification;
use App\Notifications\TaskProgressNotification;
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
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Feature\QualityPass\Performance\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * Decision D-14, amended by the owner on 2026-09-28: the assignee of a task
 * reports on it — starts it (Pending → In progress), posts progress updates
 * and completes it — and every report lands in the task's immutable
 * progress log (task_updates), shown on the task page newest first. A start
 * or an update tells the assigner under the completion's recipient rule —
 * never the actor, only after the write commits, bell by default and mail
 * opt-in (NotificationEvent::TaskProgress); completion keeps its own notice.
 */
final class TaskProgressTest extends TestCase
{
    use CountsQueries;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $admin;

    private User $rep;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-09-28 09:00:00'));
        config()->set('mail.default', 'log');

        $this->admin = $this->admin();
        $this->rep = $this->salesRep();
        $this->tasks = app(TaskService::class);
    }

    // --- The service: transitions, validation, the log, the ledger ---

    #[Test]
    public function starting_moves_a_pending_task_in_progress_and_logs_it(): void
    {
        $task = $this->handedOut();

        $this->tasks->start($task, $this->rep, '  Calling them this afternoon  ');

        $this->assertSame(TaskStatus::InProgress, $task->status, 'the caller\'s instance is refreshed');
        $this->assertNull($task->completed_at);

        $entry = TaskUpdate::query()->where('task_id', $task->getKey())->sole();
        $this->assertSame(TaskStatus::InProgress, $entry->status);
        $this->assertSame('Calling them this afternoon', $entry->body);
        $this->assertSame($this->rep->getKey(), (int) $entry->user_id);
        $this->assertSame('2026-09-28 09:00:00', $entry->created_at->format('Y-m-d H:i:s'));

        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskStarted->value, 'subject_id' => $task->getKey(), 'causer_id' => $this->rep->getKey()]);
    }

    #[Test]
    public function a_start_without_a_note_keeps_an_empty_body(): void
    {
        $task = $this->handedOut();

        $this->tasks->start($task, $this->rep, '   ');

        $this->assertNull(TaskUpdate::query()->where('task_id', $task->getKey())->sole()->body);
    }

    #[Test]
    public function only_a_pending_task_can_be_started(): void
    {
        $task = $this->handedOut();
        $this->tasks->start($task, $this->rep);

        try {
            $this->tasks->start($task, $this->rep);
            $this->fail('an in-progress task was started again');
        } catch (InvalidTaskTransitionException $exception) {
            $this->assertSame(__('tasks.validation.not_pending'), $exception->getMessage());
        }

        $done = Task::factory()->completed()->create(['assignee_id' => $this->rep->getKey()]);

        $this->expectException(InvalidTaskTransitionException::class);
        $this->tasks->start($done, $this->rep);
    }

    #[Test]
    public function a_deleted_task_cannot_be_started_or_updated(): void
    {
        $task = $this->handedOut();
        $task->delete();

        foreach ([
            fn () => $this->tasks->start($task, $this->rep),
            fn () => $this->tasks->postUpdate($task, $this->rep, 'Still working on it'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a deleted task accepted a progress report');
            } catch (InvalidTaskTransitionException $exception) {
                $this->assertSame(__('tasks.validation.trashed'), $exception->getMessage());
            }
        }

        $this->assertSame(0, TaskUpdate::query()->count());
    }

    #[Test]
    public function a_start_note_longer_than_the_limit_is_refused_before_anything_changes(): void
    {
        $task = $this->handedOut();

        try {
            $this->tasks->start($task, $this->rep, str_repeat('a', TaskService::PROGRESS_TEXT_MAX + 1));
            $this->fail('an over-long note was accepted');
        } catch (ValidationException $exception) {
            $this->assertSame([__('tasks.validation.update_body_too_long', ['max' => TaskService::PROGRESS_TEXT_MAX])], $exception->errors()['body']);
        }

        $this->assertSame(TaskStatus::Pending, $task->refresh()->status);
        $this->assertSame(0, TaskUpdate::query()->count());
    }

    #[Test]
    public function posting_an_update_logs_the_trimmed_text_without_moving_the_status(): void
    {
        $task = $this->handedOut();

        $update = $this->tasks->postUpdate($task, $this->rep, "  Met the buyer.\nWaiting for the PO.  ");

        $this->assertNull($update->status);
        $this->assertSame("Met the buyer.\nWaiting for the PO.", $update->body);
        $this->assertSame(TaskStatus::Pending, $task->refresh()->status);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskProgressPosted->value, 'subject_id' => $task->getKey(), 'causer_id' => $this->rep->getKey()]);

        $at = $this->textOfLength(TaskService::PROGRESS_TEXT_MAX);
        $this->assertSame($at, $this->tasks->postUpdate($task, $this->rep, $at)->body, 'the limit itself is accepted');
    }

    #[Test]
    public function an_update_needs_text_within_the_limit(): void
    {
        $task = $this->handedOut();

        foreach ([
            '' => __('tasks.validation.update_body_required'),
            "  \n  " => __('tasks.validation.update_body_required'),
            $this->textOfLength(TaskService::PROGRESS_TEXT_MAX + 1) => __('tasks.validation.update_body_too_long', ['max' => TaskService::PROGRESS_TEXT_MAX]),
        ] as $body => $message) {
            try {
                $this->tasks->postUpdate($task, $this->rep, (string) $body);
                $this->fail('an invalid update was accepted');
            } catch (ValidationException $exception) {
                $this->assertSame([$message], $exception->errors()['body']);
            }
        }

        $this->assertSame(0, TaskUpdate::query()->count());
    }

    #[Test]
    public function a_closed_task_takes_no_update(): void
    {
        $task = Task::factory()->completed()->create(['assignee_id' => $this->rep->getKey()]);

        $this->expectExceptionMessage(__('tasks.validation.update_not_open'));

        $this->tasks->postUpdate($task, $this->rep, 'Too late');
    }

    #[Test]
    public function completing_and_reopening_are_kept_in_the_log_too(): void
    {
        $task = $this->handedOut();

        $this->tasks->complete($task, $this->rep, 'Signed and filed');
        $this->tasks->reopen($task, $this->rep);
        $this->tasks->complete($task, $this->rep);

        $entries = $task->updates()->get();

        $this->assertSame([TaskStatus::Completed, TaskStatus::Pending, TaskStatus::Completed], $entries->map(static fn (TaskUpdate $entry): ?TaskStatus => $entry->status)->all(), 'newest first');
        $this->assertSame([null, null, 'Signed and filed'], $entries->pluck('body')->all());
    }

    #[Test]
    public function the_log_is_append_only_and_survives_a_delete_and_a_restore(): void
    {
        $task = $this->handedOut();
        $entry = $this->tasks->postUpdate($task, $this->rep, 'Halfway there');

        try {
            $entry->update(['body' => 'Rewritten']);
            $this->fail('a log entry was edited');
        } catch (LogicException) {
            // expected
        }

        try {
            $entry->delete();
            $this->fail('a log entry was deleted');
        } catch (LogicException) {
            // expected
        }

        $task->delete();
        $task->restore();

        $this->assertSame('Halfway there', $task->updates()->sole()->body);
    }

    #[Test]
    public function the_status_column_of_the_log_is_constrained_to_the_task_statuses(): void
    {
        $task = $this->handedOut();

        $this->expectException(QueryException::class);

        DB::table('task_updates')->insert(['task_id' => $task->getKey(), 'status' => 'archived', 'created_at' => now()]);
    }

    // --- Notifications ---

    #[Test]
    public function a_start_and_an_update_tell_the_assigner_on_the_bell_and_never_the_actor(): void
    {
        $task = $this->handedOut();

        Notification::fake();

        $this->tasks->start($task, $this->rep, 'On it');
        $this->tasks->postUpdate($task, $this->rep, 'Quote sent');

        Notification::assertSentToTimes($this->admin, TaskProgressNotification::class, 2);
        Notification::assertSentTo(
            $this->admin,
            TaskProgressNotification::class,
            static fn (TaskProgressNotification $notification, array $channels): bool => $channels === ['database'],
        );
        Notification::assertNotSentTo($this->rep, TaskProgressNotification::class);
    }

    #[Test]
    public function completion_keeps_its_own_notice_and_sends_no_progress_notice(): void
    {
        $task = $this->handedOut();

        Notification::fake();

        $this->tasks->complete($task, $this->rep, 'Done');

        Notification::assertSentTo($this->admin, TaskCompletedNotification::class);
        Notification::assertNotSentTo($this->admin, TaskProgressNotification::class);
    }

    #[Test]
    public function the_assigner_reporting_on_their_own_task_notifies_nobody(): void
    {
        $own = $this->tasks->create(['title' => 'My own follow-up', 'assignee_id' => $this->rep->getKey()], $this->rep);

        Notification::fake();

        $this->tasks->start($own, $this->rep);
        $this->tasks->postUpdate($own, $this->rep, 'Progressing');

        Notification::assertNothingSent();
    }

    #[Test]
    public function the_creator_is_told_when_no_assigner_was_recorded_and_only_if_they_may_open_the_task(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $legacy = Task::factory()->create(['assignee_id' => $rep->getKey(), 'assigned_by' => null, 'created_by' => $manager->getKey()]);

        $outsider = $this->salesManager($this->makeTeam('Jeddah Team', 'فريق جدة'));
        $hidden = Task::factory()->create(['assignee_id' => $rep->getKey(), 'assigned_by' => null, 'created_by' => $outsider->getKey()]);
        $this->assertFalse($outsider->can('view', $hidden), 'precondition: the other team\'s manager may not open the task');

        Notification::fake();

        $this->tasks->start($legacy, $rep);
        $this->tasks->start($hidden, $rep);

        Notification::assertSentTo($manager, TaskProgressNotification::class);
        Notification::assertNotSentTo($outsider, TaskProgressNotification::class);
    }

    #[Test]
    public function a_recipient_who_may_no_longer_sign_in_is_skipped(): void
    {
        $task = $this->handedOut();
        $this->admin->update(['status' => UserStatus::Disabled]);

        Notification::fake();

        $this->tasks->postUpdate($task, $this->rep, 'Anyone there?');

        Notification::assertNothingSent();
    }

    #[Test]
    public function nothing_leaves_when_the_surrounding_write_rolls_back(): void
    {
        $task = $this->handedOut();

        Notification::fake();

        try {
            DB::transaction(function () use ($task): void {
                $this->tasks->start($task, $this->rep, 'Started');
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rolled back', $exception->getMessage());
        }

        Notification::assertNothingSent();
        $this->assertSame(TaskStatus::Pending, $task->refresh()->status);
        $this->assertSame(0, TaskUpdate::query()->count());
    }

    #[Test]
    public function mail_is_opt_in_for_progress_and_joins_the_bell_when_chosen(): void
    {
        $task = $this->handedOut();
        config()->set('mail.default', 'smtp');

        $this->assertTrue(NotificationEvent::TaskProgress->databaseByDefault());
        $this->assertFalse(NotificationEvent::TaskProgress->mailByDefault());
        $this->assertSame(['database'], NotificationChannels::for($this->admin, NotificationEvent::TaskProgress), 'mail stays off until the user opts in');

        NotificationPreference::factory()
            ->ofEvent(NotificationEvent::TaskProgress)
            ->withMail()
            ->create(['user_id' => $this->admin->getKey()]);

        Notification::fake();

        $this->tasks->postUpdate($task, $this->rep, 'Contract drafted');

        Notification::assertSentTo(
            $this->admin,
            TaskProgressNotification::class,
            static fn (TaskProgressNotification $notification, array $channels): bool => $channels === ['database', 'mail'],
        );
    }

    #[Test]
    public function the_progress_bell_is_written_at_once_while_its_mail_is_queued(): void
    {
        $task = $this->handedOut();
        $entry = $this->tasks->postUpdate($task, $this->rep, 'Queued');
        $notification = new TaskProgressNotification($task, $this->rep, $entry);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame(['database' => 'sync'], $notification->viaConnections());
    }

    #[Test]
    public function the_strings_resolve_in_both_locales(): void
    {
        $task = Task::factory()->create(['assignee_id' => $this->rep->getKey(), 'title' => 'Send the brochure']);
        $recipient = User::factory()->create(['name' => 'Recipient']);
        $started = TaskUpdate::query()->create(['task_id' => $task->getKey(), 'user_id' => $this->rep->getKey(), 'status' => TaskStatus::InProgress, 'body' => null]);
        $startedWithNote = TaskUpdate::query()->create(['task_id' => $task->getKey(), 'user_id' => $this->rep->getKey(), 'status' => TaskStatus::InProgress, 'body' => 'Printing today']);
        $posted = TaskUpdate::query()->create(['task_id' => $task->getKey(), 'user_id' => $this->rep->getKey(), 'status' => null, 'body' => 'Courier booked']);

        app()->setLocale('ar');
        $this->assertSame('بدأ العمل على المهمة «Send the brochure»', (new TaskProgressNotification($task, $this->rep, $started))->toMail($recipient)->subject);
        $this->assertSame(['بدأ Sales Rep العمل على المهمة «Send the brochure».'], (new TaskProgressNotification($task, $this->rep, $started))->toMail($recipient)->introLines);
        $this->assertSame(['بدأ Sales Rep العمل على المهمة «Send the brochure»: Printing today'], (new TaskProgressNotification($task, $this->rep, $startedWithNote))->toMail($recipient)->introLines);
        $this->assertSame('تحديث على المهمة «Send the brochure»', (new TaskProgressNotification($task, $this->rep, $posted))->toMail($recipient)->subject);
        $this->assertSame(['نشر Sales Rep تحديثاً على المهمة «Send the brochure»: Courier booked'], (new TaskProgressNotification($task, $this->rep, $posted))->toMail($recipient)->introLines);

        app()->setLocale('en');
        $this->assertSame('Task "Send the brochure" started', (new TaskProgressNotification($task, $this->rep, $started))->toMail($recipient)->subject);
        $this->assertSame(['Sales Rep started the task "Send the brochure".'], (new TaskProgressNotification($task, $this->rep, $started))->toMail($recipient)->introLines);
        $this->assertSame(['Sales Rep started the task "Send the brochure": Printing today'], (new TaskProgressNotification($task, $this->rep, $startedWithNote))->toMail($recipient)->introLines);
        $this->assertSame('Progress on "Send the brochure"', (new TaskProgressNotification($task, $this->rep, $posted))->toMail($recipient)->subject);
        $this->assertSame(['Sales Rep posted an update on "Send the brochure": Courier booked'], (new TaskProgressNotification($task, $this->rep, $posted))->toMail($recipient)->introLines);
    }

    #[Test]
    public function the_authors_text_reaches_the_bell_as_plain_text_and_cut_to_the_limit(): void
    {
        $task = $this->handedOut(['title' => 'Plain <i>title</i>']);

        $this->tasks->postUpdate($task, $this->rep, 'Use <b>bold</b> & "quotes"');

        $bell = $this->admin->notifications()->sole()->data;
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; &amp;', (string) $bell['body']);
        $this->assertStringNotContainsString('<b>', (string) $bell['body']);
        $this->assertStringContainsString('Plain &lt;i&gt;title&lt;/i&gt;', (string) $bell['title']);

        $long = new TaskProgressNotification($task, $this->rep, $this->tasks->postUpdate($task, $this->rep, str_repeat('word ', 200)));
        $line = $long->toMail($this->admin)->introLines[0];

        $this->assertStringEndsWith('...', $line);
        $this->assertLessThan(TaskProgressNotification::NOTE_LIMIT + 120, mb_strlen($line), 'the quoted text is cut to the limit');
    }

    // --- The task page ---

    #[Test]
    public function the_task_page_lists_the_progress_newest_first_with_authors_statuses_and_text(): void
    {
        $task = $this->handedOut();
        $this->tasks->start($task, $this->rep, 'Calling them first');
        $this->travel(5)->minutes();
        $this->tasks->postUpdate($task, $this->rep, 'They want a <b>discount</b>');
        $this->travel(5)->minutes();
        $this->tasks->complete($task, $this->rep, 'Agreed at five percent');

        Livewire::actingAs($this->admin)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertOk()
            ->assertSee(__('tasks.sections.thread'))
            ->assertSeeHtmlInOrder(['Agreed at five percent', 'They want a &lt;b&gt;discount&lt;/b&gt;', 'Calling them first'])
            ->assertSee('Sales Rep')
            ->assertSee(TaskStatus::InProgress->getLabel())
            ->assertSee('2026-09-28 09:10')
            ->assertDontSee(__('tasks.empty.thread'));
    }

    #[Test]
    public function the_task_page_shows_the_empty_state_and_a_departed_authors_fallback(): void
    {
        $task = $this->handedOut();

        Livewire::actingAs($this->admin)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSee(__('tasks.empty.thread'));

        $this->tasks->postUpdate($task, $this->rep, 'Before leaving');
        $this->rep->delete();

        Livewire::actingAs($this->admin)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSee(__('tasks.empty.deleted_author_named', ['name' => 'Sales Rep']));

        // An author row removed outright (the column's nullOnDelete).
        DB::table('task_updates')->where('task_id', $task->getKey())->update(['user_id' => null]);

        Livewire::actingAs($this->admin)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSee(__('tasks.empty.deleted_author'));
    }

    #[Test]
    public function the_assignee_starts_and_posts_from_the_task_page_and_sees_the_entry_at_once(): void
    {
        $task = $this->handedOut();

        Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('start', data: ['start_note' => 'Leaving now'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('tasks.notifications.started'))
            ->assertActionHidden('start');

        foreach (['' => 'required', str_repeat('x', TaskService::PROGRESS_TEXT_MAX + 1) => 'max'] as $body => $rule) {
            Livewire::actingAs($this->rep)
                ->test(ViewTask::class, ['record' => $task->getKey()])
                ->callAction('postUpdate', data: ['body' => (string) $body])
                ->assertHasActionErrors(['body' => $rule]);
        }

        Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('postUpdate', data: ['body' => 'Arrived at the client'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('tasks.notifications.update_posted'))
            ->assertSee('Arrived at the client');

        $this->assertSame(TaskStatus::InProgress, $task->refresh()->status);
        $this->assertSame(2, $task->updates()->count());
    }

    #[Test]
    public function the_report_modals_promise_a_notice_only_when_someone_will_receive_it(): void
    {
        $promise = __('tasks.helpers.update_notifies');
        $handedOut = $this->handedOut();
        $own = $this->tasks->create(['title' => 'My own follow-up', 'assignee_id' => $this->rep->getKey()], $this->rep);

        $this->assertTrue($this->tasks->reportsReachSomeone($handedOut, $this->rep));
        $this->assertFalse($this->tasks->reportsReachSomeone($handedOut, $this->admin), 'the assigner reporting on their own hand-out');
        $this->assertFalse($this->tasks->reportsReachSomeone($own, $this->rep), 'a task the rep created for themselves');

        // The modal renders lazily, so the field's helper (its below-content text) is read off the mounted schema.
        $helper = static fn (?string $expected): Closure => static function (Textarea $field) use ($expected): bool {
            $texts = array_values(array_filter(
                $field->getChildSchema(Textarea::BELOW_CONTENT_SCHEMA_KEY)?->getComponents() ?? [],
                static fn (mixed $component): bool => $component instanceof Text,
            ));

            return array_map(static fn (Text $text): string => (string) $text->getContent(), $texts) === ($expected === null ? [] : [$expected]);
        };

        foreach (['start' => 'start_note', 'postUpdate' => 'body'] as $action => $field) {
            Livewire::actingAs($this->rep)
                ->test(ViewTask::class, ['record' => $handedOut->getKey()])
                ->mountAction($action)
                ->assertFormFieldExists($field, $helper($promise));

            Livewire::actingAs($this->rep)
                ->test(ViewTask::class, ['record' => $own->getKey()])
                ->mountAction($action)
                ->assertFormFieldExists($field, $helper(null));

            Livewire::actingAs($this->admin)
                ->test(ViewTask::class, ['record' => $handedOut->getKey()])
                ->mountAction($action)
                ->assertFormFieldExists($field, $helper(null));
        }

        $this->admin->update(['status' => UserStatus::Disabled]);

        $this->assertFalse($this->tasks->reportsReachSomeone($handedOut->refresh(), $this->rep), 'a disabled assigner receives nothing');

        Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $handedOut->getKey()])
            ->mountAction('postUpdate')
            ->assertFormFieldExists('body', $helper(null));
    }

    #[Test]
    public function the_report_actions_disappear_once_the_task_is_closed(): void
    {
        $task = $this->handedOut();
        $this->tasks->complete($task, $this->rep);

        Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertActionHidden('start')
            ->assertActionHidden('postUpdate')
            ->assertActionHidden('complete')
            ->assertActionVisible('reopen');
    }

    // --- The shared tasks board ---

    #[Test]
    public function the_board_counts_a_started_task_in_progress_and_badges_its_card(): void
    {
        $task = $this->handedOut(['title' => 'Visit the client']);
        $label = TaskStatus::InProgress->getLabel();

        $before = Livewire::actingAs($this->admin)->test(TasksBoard::class);
        $before->assertViewHas('stats', static fn (array $stats): bool => $stats['in_progress'] === 0);

        $this->tasks->start($task, $this->rep);

        $after = Livewire::actingAs($this->admin)->test(TasksBoard::class);
        $after->assertViewHas('stats', static fn (array $stats): bool => $stats['in_progress'] === 1);

        $this->assertSame(substr_count($before->html(), $label) + 1, substr_count($after->html(), $label), 'the started card carries the status badge');
    }

    #[Test]
    public function the_badges_cost_no_query_per_card(): void
    {
        $this->startTasks(2);
        $this->renderBoard();

        $small = $this->queriesDuring(fn () => $this->renderBoard());

        $this->startTasks(12);

        $large = $this->queriesDuring(fn () => $this->renderBoard());

        $this->assertLessThanOrEqual(count($small) + 3, count($large), sprintf("TasksBoard: %d queries for 2 started cards, %d for 14.\n%s", count($small), count($large), $this->topRepeated($large)));
    }

    private function startTasks(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->tasks->start(Task::factory()->create(['assignee_id' => $this->makeUser(CrmRole::SalesRep, ['name' => 'Rep '.$i.'-'.uniqid()])->getKey()]), $this->admin);
        }
    }

    private function renderBoard(): void
    {
        Livewire::actingAs($this->admin)->test(TasksBoard::class)->assertOk();
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

    /** Text of exactly the given length, with words, so trimming changes nothing. */
    private function textOfLength(int $length): string
    {
        return substr(str_repeat('progress ', (int) ceil($length / 9) + 1), 0, $length - 1).'.';
    }
}
