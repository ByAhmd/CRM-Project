<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Filament\Pages\Calendar;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\DealTasksRelationManager;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Attachment;
use App\Models\Deal;
use App\Models\Task;
use App\Models\TaskUpdateMention;
use App\Models\Team;
use App\Models\User;
use App\Notifications\TaskMentionNotification;
use App\Services\Tasks\TaskService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\RichEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Feature\QualityPass\Performance\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * Decision D-20: a mention makes the colleague a participant of the task — a
 * third read path beside the own/team/all scope and the linked-record path.
 * They may open the task, list it, find it, read its thread and its files
 * (downloads still need `attachment.download`) and comment on it; they never
 * gain edit, progress, cancel, delete, restore or assign rights. The comment
 * box is a mention-aware editor whose document becomes plain text holding
 * the colleague's real name, and the thread highlights the names a comment
 * mentions without ever reading a name as markup.
 */
final class TaskMentionAccessTest extends TestCase
{
    use CountsQueries;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    private User $assignee;

    private User $colleague;

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
        $this->assignee = $this->makeUser(CrmRole::Employee, ['name' => 'Huda Employee'], $this->team);
        $this->colleague = $this->makeUser(CrmRole::Employee, ['name' => 'Omar Employee'], $this->makeTeam('Jeddah Team', 'فريق جدة'));
        $this->tasks = app(TaskService::class);
    }

    // --- The participant read path ---

    #[Test]
    public function a_mentioned_colleague_may_open_and_comment_on_the_task_and_nothing_more(): void
    {
        $task = $this->handedToAssignee();

        $this->assertFalse($this->colleague->can('view', $task), 'precondition: an employee sees only their own tasks');
        $this->actingAs($this->colleague)->get(TaskResource::getUrl('view', ['record' => $task]))->assertNotFound();

        $this->mention($task, $this->assignee, $this->colleague);

        $this->assertTrue($this->colleague->can('view', $task));
        $this->assertTrue($this->colleague->can('comment', $task));

        foreach (['update', 'progress', 'start', 'postUpdate', 'complete', 'cancel', 'reopen', 'delete', 'restore', 'assign'] as $ability) {
            $this->assertFalse($this->colleague->can($ability, $task), "a participant may not {$ability} the task");
        }

        $this->actingAs($this->colleague)->get(TaskResource::getUrl('view', ['record' => $task]))->assertOk();
        $this->actingAs($this->colleague)->get(TaskResource::getUrl('edit', ['record' => $task]))->assertForbidden();

        Livewire::actingAs($this->colleague)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertActionVisible('comment')
            ->assertActionHidden('edit')
            ->assertActionHidden('start')
            ->assertActionHidden('postUpdate')
            ->assertActionHidden('complete')
            ->assertActionHidden('cancel')
            ->assertActionHidden('delete')
            ->assertActionHidden('assign')
            ->assertOk();

        // Completed by its assignee, the task stays readable; reopening is still not the participant's.
        $this->tasks->complete($task, $this->assignee);

        $this->assertTrue($this->colleague->can('view', $task->refresh()));
        $this->assertFalse($this->colleague->can('reopen', $task));
    }

    #[Test]
    public function a_participant_can_neither_move_nor_edit_the_task_from_the_calendar(): void
    {
        $task = $this->tasks->create(['title' => 'Count the stock', 'assignee_id' => $this->assignee->getKey(), 'due_at' => '2026-10-06 10:00:00'], $this->admin);
        $dueAt = $task->due_at?->toDateTimeString();
        $this->mention($task, $this->assignee, $this->colleague);

        Livewire::actingAs($this->colleague)
            ->test(Calendar::class)
            ->call('moveTask', $task->getKey(), '2026-10-08T09:30:00+03:00', null, false)
            ->assertForbidden();

        Livewire::actingAs($this->colleague)
            ->test(Calendar::class)
            ->assertActionHidden(TestAction::make('editTask')->arguments(['task' => $task->getKey()]));

        $this->assertSame($dueAt, $task->refresh()->due_at?->toDateTimeString(), 'the task did not move');
    }

    #[Test]
    public function the_participant_lists_and_finds_the_task_and_nothing_beyond_it(): void
    {
        $mentioned = $this->handedToAssignee('Count the stock');
        $other = $this->handedToAssignee('Visit the warehouse');
        $this->mention($mentioned, $this->assignee, $this->colleague);

        Livewire::actingAs($this->colleague)
            ->test(ListTasks::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$mentioned])
            ->assertCanNotSeeTableRecords([$other])
            ->assertTableActionVisible('comment', $mentioned)
            ->assertTableActionHidden('edit', $mentioned)
            ->assertTableActionHidden('complete', $mentioned);

        $this->actingAs($this->colleague);

        $this->assertSame([$mentioned->getKey()], TaskResource::getGlobalSearchEloquentQuery()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
        $this->assertSame([$mentioned->getKey()], TaskResource::getEloquentQuery()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
    }

    #[Test]
    public function the_participant_path_reaches_past_a_reps_and_a_managers_own_scope(): void
    {
        $outside = $this->handedToAssignee('Outside every sales scope');
        $rep = $this->salesRep($this->makeTeam('Dammam Team', 'فريق الدمام'));
        $manager = $this->salesManager($this->makeTeam('Abha Team', 'فريق أبها'));

        foreach ([$rep, $manager] as $reader) {
            $this->assertFalse($reader->can('view', $outside), "precondition: {$reader->name} cannot reach the task");
        }

        $this->tasks->comment($outside, $this->admin, '@Sales Rep @Sales Manager please advise', [(int) $rep->getKey(), (int) $manager->getKey()]);

        foreach ([$rep, $manager] as $reader) {
            $this->assertTrue($reader->can('view', $outside), "{$reader->name} now takes part");
            $this->assertFalse($reader->can('update', $outside), "{$reader->name} still may not edit it");

            $this->actingAs($reader);
            $this->assertContains($outside->getKey(), TaskResource::getEloquentQuery()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
        }
    }

    #[Test]
    public function a_participant_who_loses_task_view_any_loses_the_task(): void
    {
        $task = $this->handedToAssignee();
        $reader = $this->userWithPermissions(null, [Permission::TaskViewAny]);
        $this->mention($task, $this->assignee, $reader);

        $this->assertTrue($reader->can('view', $task));

        $reader->revokePermissionTo(Permission::TaskViewAny->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $reader = $reader->fresh() ?? $reader;

        $this->assertFalse($reader->can('view', $task), 'view_any stays the precondition of every read');
        $this->actingAs($reader);
        $this->assertSame([], TaskResource::getEloquentQuery()->pluck('id')->all());
        $this->assertSame(1, TaskUpdateMention::query()->count(), 'the mention itself is permanent');
    }

    #[Test]
    public function a_participant_reads_the_tasks_files_only_with_the_download_permission(): void
    {
        $task = $this->handedToAssignee();
        $file = Attachment::factory()->forSubject($task)->create();
        $readerWithoutDownloads = $this->userWithPermissions(null, [Permission::TaskViewAny]);

        $this->assertFalse($this->colleague->can('download', $file), 'precondition: the file is out of reach');

        $this->tasks->comment($task, $this->assignee, '@Omar Employee @someone see the file', [(int) $this->colleague->getKey(), (int) $readerWithoutDownloads->getKey()]);

        $this->assertTrue($this->colleague->can('download', $file), 'an employee holds attachment.download (D-15)');
        $this->assertTrue($readerWithoutDownloads->can('view', $task));
        $this->assertFalse($readerWithoutDownloads->can('download', $file), 'downloads still need attachment.download');
    }

    #[Test]
    public function listing_many_participant_tasks_costs_no_query_per_row(): void
    {
        $this->participantTasks(2);

        $render = fn () => Livewire::actingAs($this->colleague)->test(ListTasks::class)->set('activeTab', 'all')->assertOk();
        $render();

        $small = $this->queriesDuring($render);

        $this->participantTasks(10);

        $large = $this->queriesDuring($render);

        $this->assertCount(12, TaskUpdateMention::query()->where('user_id', $this->colleague->getKey())->get());
        $this->assertLessThanOrEqual(count($small), count($large), sprintf("ListTasks: %d queries for 2 participant tasks, %d for 12.\n%s", count($small), count($large), $this->topRepeated($large)));
    }

    // --- The comment box ---

    #[Test]
    public function the_comment_box_turns_a_mention_into_the_colleagues_real_name_and_a_stored_mention(): void
    {
        $task = $this->handedToAssignee();

        Notification::fake();

        Livewire::actingAs($this->assignee)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('comment', data: ['comment' => $this->editorDocument(
                ['type' => 'text', 'text' => 'Please check the shelves, '],
                ['type' => 'mention', 'attrs' => ['id' => (string) $this->colleague->getKey(), 'label' => 'Forged Label', 'char' => '@']],
                ['type' => 'text', 'text' => '.', 'marks' => [['type' => 'bold']]],
            )])
            ->assertHasNoActionErrors()
            ->assertNotified(__('tasks.notifications.comment_posted'));

        $comment = $task->updates()->sole();

        $this->assertSame('Please check the shelves, @Omar Employee.', $comment->body);
        $this->assertSame([$this->colleague->getKey()], TaskUpdateMention::query()->where('task_update_id', $comment->getKey())->pluck('user_id')->map(static fn (mixed $id): int => (int) $id)->all());
        Notification::assertSentTo($this->colleague, TaskMentionNotification::class);
        $this->assertTrue($this->colleague->can('view', $task));
    }

    #[Test]
    public function the_comment_box_drops_a_forged_mention_and_refuses_a_comment_left_empty(): void
    {
        $task = $this->handedToAssignee();
        $outsider = $this->userWithPermissions(null, [Permission::LeadViewAny]);

        Livewire::actingAs($this->assignee)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('comment', data: ['comment' => $this->editorDocument(
                ['type' => 'mention', 'attrs' => ['id' => '999999', 'label' => 'Ghost', 'char' => '@']],
            )])
            ->assertHasActionErrors(['comment' => 'required']);

        Livewire::actingAs($this->assignee)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('comment', data: ['comment' => $this->editorDocument(
                ['type' => 'text', 'text' => 'Only for the team '],
                ['type' => 'mention', 'attrs' => ['id' => (string) $outsider->getKey(), 'label' => 'Outsider', 'char' => '@']],
            )])
            ->assertHasNoActionErrors();

        $this->assertSame(0, TaskUpdateMention::query()->count(), 'a user without task.view_any is never mentioned');
        $this->assertFalse($outsider->can('view', $task));
        $this->assertSame(1, $task->updates()->count());

        Livewire::actingAs($this->assignee)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('comment', data: ['comment' => $this->editorDocument(
                ['type' => 'text', 'text' => str_repeat('x', TaskService::COMMENT_TEXT_MAX + 1)],
            )])
            ->assertHasActionErrors(['comment' => 'max']);
    }

    #[Test]
    public function the_mention_list_offers_the_mentionable_colleagues_by_name_in_both_directions(): void
    {
        $task = $this->handedToAssignee();
        $disabledNamesake = $this->makeUser(CrmRole::Employee, ['name' => 'Omar Disabled', 'status' => UserStatus::Disabled]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            Livewire::actingAs($this->assignee)
                ->test(ViewTask::class, ['record' => $task->getKey()])
                ->mountAction('comment')
                ->assertFormFieldExists('comment', function (RichEditor $field) use ($disabledNamesake): bool {
                    $this->assertSame([], $field->getToolbarButtons(), 'no formatting toolbar');

                    // A bare "@" already lists colleagues: the first ones by name, never the author or a disabled account.
                    $listed = $field->getMentionsForJs()[0]['items'];
                    $this->assertSame([(string) $this->admin->getKey() => $this->admin->name, (string) $this->colleague->getKey() => 'Omar Employee'], $listed);
                    $this->assertLessThanOrEqual(8, count($listed));

                    $this->assertSame([(string) $this->colleague->getKey() => 'Omar Employee'], $field->getMentionSearchResultsForJs('Omar'));
                    $this->assertArrayNotHasKey((string) $this->assignee->getKey(), $field->getMentionSearchResultsForJs(''), 'the author is never offered');
                    $this->assertArrayNotHasKey((string) $disabledNamesake->getKey(), $field->getMentionSearchResultsForJs(''));
                    $this->assertLessThanOrEqual(8, count($field->getMentionSearchResultsForJs('')));
                    $this->assertSame(
                        [(string) $this->colleague->getKey() => 'Omar Employee'],
                        $field->getMentionLabelsForJs([
                            ['id' => (string) $this->colleague->getKey(), 'char' => '@'],
                            ['id' => (string) $disabledNamesake->getKey(), 'char' => '@'],
                        ]),
                    );

                    return true;
                });
        }
    }

    #[Test]
    public function the_row_menus_comment_box_mentions_colleagues_too(): void
    {
        $deal = Deal::factory()->create(['owner_id' => $this->admin->getKey()]);
        $task = $this->tasks->create(['title' => 'Count the stock', 'assignee_id' => $this->assignee->getKey(), 'deal_id' => $deal->getKey()], $this->admin);
        $rep = $this->salesRep($this->makeTeam('Dammam Team', 'فريق الدمام'));

        Notification::fake();

        Livewire::actingAs($this->assignee)
            ->test(ListTasks::class)
            ->mountTableAction('comment', $task)
            ->assertFormFieldExists('comment', function (RichEditor $field): bool {
                $this->assertSame([(string) $this->colleague->getKey() => 'Omar Employee'], $field->getMentionSearchResultsForJs('Omar'));

                return true;
            });

        Livewire::actingAs($this->assignee)
            ->test(ListTasks::class)
            ->callTableAction('comment', $task, data: ['comment' => $this->editorDocument(
                ['type' => 'text', 'text' => 'From the list, '],
                ['type' => 'mention', 'attrs' => ['id' => (string) $this->colleague->getKey(), 'label' => 'Omar Employee', 'char' => '@']],
            )])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('tasks.notifications.comment_posted'));

        Livewire::actingAs($this->admin)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->callTableAction('comment', $task, data: ['comment' => $this->editorDocument(
                ['type' => 'text', 'text' => 'From the deal, '],
                ['type' => 'mention', 'attrs' => ['id' => (string) $rep->getKey(), 'label' => 'Sales Rep', 'char' => '@']],
            )])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('tasks.notifications.comment_posted'));

        $this->assertSame(['From the deal, @Sales Rep', 'From the list, @Omar Employee'], $task->updates()->pluck('body')->all());
        $this->assertEqualsCanonicalizing(
            [$this->colleague->getKey(), $rep->getKey()],
            TaskUpdateMention::query()->where('task_id', $task->getKey())->pluck('user_id')->map(static fn (mixed $id): int => (int) $id)->all(),
        );
        Notification::assertSentTo($this->colleague, TaskMentionNotification::class);
        Notification::assertSentTo($rep, TaskMentionNotification::class);
    }

    // --- The thread ---

    #[Test]
    public function the_thread_highlights_the_mentioned_names_only(): void
    {
        $task = $this->handedToAssignee();
        $sara = $this->makeUser(CrmRole::Employee, ['name' => 'Sara'], $this->team);
        $saraAli = $this->makeUser(CrmRole::Employee, ['name' => 'Sara Ali'], $this->team);

        $huda = $this->makeUser(CrmRole::Employee, ['name' => 'هدى'], $this->team);

        $this->tasks->comment($task, $this->assignee, "@Sara Ali and @Sara, not @Omar Employee, mail@Sara or @Sarah\nو @هدى لا @هدىً", [(int) $saraAli->getKey(), (int) $sara->getKey(), (int) $huda->getKey()]);

        $page = Livewire::actingAs($this->admin)->test(ViewTask::class, ['record' => $task->getKey()]);
        $page->assertOk();
        $html = $page->html();

        $this->assertStringContainsString(
            '<span class="crm-mention">@Sara Ali</span> and <span class="crm-mention">@Sara</span>, not @Omar Employee, mail@Sara or @Sarah'."\n".'و <span class="crm-mention">@هدى</span> لا @هدىً',
            $html,
            'only the mentioned names are marked: not an unmentioned colleague, not inside an address or a longer word, in either script',
        );
    }

    #[Test]
    public function a_name_that_looks_like_markup_is_shown_as_text(): void
    {
        $task = $this->handedToAssignee();
        $hostile = $this->makeUser(CrmRole::Employee, ['name' => '<img src=x onerror=alert(1)>'], $this->team);

        Livewire::actingAs($this->assignee)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('comment', data: ['comment' => $this->editorDocument(
                ['type' => 'text', 'text' => 'Ask '],
                ['type' => 'mention', 'attrs' => ['id' => (string) $hostile->getKey(), 'label' => 'x', 'char' => '@']],
                ['type' => 'text', 'text' => ' about <b>this</b>'],
            )])
            ->assertHasNoActionErrors();

        $this->assertSame('Ask @<img src=x onerror=alert(1)> about <b>this</b>', $task->updates()->sole()->body);

        $page = Livewire::actingAs($this->admin)->test(ViewTask::class, ['record' => $task->getKey()]);
        $page->assertOk();
        $html = $page->html();

        $this->assertStringContainsString('<span class="crm-mention">@&lt;img src=x onerror=alert(1)&gt;</span> about &lt;b&gt;this&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>this</b>', $html);
    }

    #[Test]
    public function the_thread_and_its_mentions_cost_no_query_per_entry(): void
    {
        $task = $this->handedToAssignee();
        $this->mentionComments($task, 2);

        $render = fn () => Livewire::actingAs($this->admin)->test(ViewTask::class, ['record' => $task->getKey()])->assertOk();
        $render();

        $small = $this->queriesDuring($render);

        $this->mentionComments($task, 10);

        $large = $this->queriesDuring($render);

        $this->assertLessThanOrEqual(count($small), count($large), sprintf("ViewTask: %d queries for 2 mentioning comments, %d for 12.\n%s", count($small), count($large), $this->topRepeated($large)));
    }

    /** A task the administrator handed to the assignee. */
    private function handedToAssignee(string $title = 'Count the stock'): Task
    {
        return $this->tasks->create(['title' => $title, 'assignee_id' => $this->assignee->getKey()], $this->admin);
    }

    private function mention(Task $task, User $author, User $mentioned): void
    {
        $this->tasks->comment($task, $author, '@'.$mentioned->name.' please have a look', [(int) $mentioned->getKey()]);
    }

    /** Tasks the colleague takes part in only through a mention. */
    private function participantTasks(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->mention($this->handedToAssignee('Shelf '.$i), $this->admin, $this->colleague);
        }
    }

    private function mentionComments(Task $task, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->mention($task, $this->assignee, $i % 2 === 0 ? $this->colleague : $this->admin);
        }
    }

    /**
     * The editor's JSON document: one paragraph holding the given inline nodes.
     *
     * @param  array<string, mixed>  ...$inline
     * @return array<string, mixed>
     */
    private function editorDocument(array ...$inline): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $inline]]];
    }
}
