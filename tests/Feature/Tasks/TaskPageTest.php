<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\DueShortcut;
use App\Enums\TaskStatus;
use App\Enums\TaskUpdateKind;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\Schemas\TaskInfolist;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use App\Filament\Support\TaskActions;
use App\Livewire\Tasks\TaskComposer;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskTemplate;
use App\Models\TaskUpdateMention;
use App\Models\User;
use App\Notifications\TaskCompletedNotification;
use App\Notifications\TaskMentionNotification;
use App\Notifications\TaskProgressNotification;
use App\Services\Tasks\TaskService;
use Filament\Actions\Testing\TestAction;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as Notifications;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Feature\QualityPass\Performance\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * The task page of the Tasks workspace (decision D-27): two columns, one
 * primary status button and the «المزيد» menu in the header, the checklist
 * ticked with one click, the properties panel with the status pill and the
 * reschedule chips, the composer (comment with @mentions, progress update)
 * and the thread filter — held against every rule it must keep: D-14 and
 * its amendment (the handed-out assignee reports, never edits, cancels or
 * moves the dates), D-17 (everyone who may open the task comments), D-20
 * (a mentioned participant reads and comments, nothing more), D-22 (the
 * routine link only for those who manage routines) and D-25 (ticking is
 * `progress` on an open task; completing with open items warns first).
 *
 * Five readers: the administrator who handed the task out (the assigner),
 * the employee it was handed to, a colleague mentioned on it, a read-only
 * user, and an outsider who may not open it.
 */
final class TaskPageTest extends TestCase
{
    use CountsQueries;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private User $participant;

    private User $outsider;

    private User $reader;

    private TaskService $tasks;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        // Wednesday 2026-10-07, 10:00 in Riyadh (the organisation clock).
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00'));
        config()->set('mail.default', 'log');
        app()->setLocale('en');

        $this->admin = $this->admin();
        $this->employee = $this->employee();
        $this->participant = $this->employee();
        $this->outsider = $this->employee();
        $this->reader = $this->readOnly();
        $this->tasks = app(TaskService::class);

        $this->task = $this->tasks->create([
            'title' => 'Prepare the visit',
            'description' => 'Bring the <b>samples</b>',
            'assignee_id' => $this->employee->getKey(),
            'due_at' => '2026-10-10 09:00:00',
            'checklist' => [['body' => 'Print the brochure'], ['body' => 'Book the car'], ['body' => 'Call the buyer']],
        ], $this->admin);

        $this->tasks->comment($this->task, $this->admin, '@'.$this->participant->name.' please help', [(int) $this->participant->getKey()]);
        $this->task->refresh();
    }

    // --- The header: one primary status button, the rest in «المزيد» ---

    #[Test]
    public function the_header_shows_one_primary_status_button_chosen_by_state_and_policy(): void
    {
        $this->assertTrue($this->participant->can('view', $this->task), 'precondition: the mention opens the task');
        $this->assertTrue($this->reader->can('view', $this->task), 'precondition: read-only users read every task');

        $this->assertPrimary($this->admin, 'statusStart', 'pending, assigner');
        $this->assertPrimary($this->employee, 'statusStart', 'pending, handed-out assignee');
        $this->assertPrimary($this->participant, null, 'pending, mentioned participant');
        $this->assertPrimary($this->reader, null, 'pending, read-only');

        $this->tasks->start($this->task, $this->employee);

        $this->assertPrimary($this->admin, 'statusComplete', 'in progress, assigner');
        $this->assertPrimary($this->employee, 'statusComplete', 'in progress, handed-out assignee');
        $this->assertPrimary($this->participant, null, 'in progress, mentioned participant');

        $this->tasks->complete($this->task, $this->employee);

        $this->assertPrimary($this->admin, 'reopen', 'completed, assigner');
        $this->assertPrimary($this->employee, 'reopen', 'completed, the assignee reopens what they completed');
        $this->assertPrimary($this->participant, null, 'completed, mentioned participant');

        $this->tasks->reopen($this->task, $this->admin);
        $this->tasks->cancel($this->task, $this->admin);

        $this->assertPrimary($this->admin, 'reopen', 'cancelled, assigner');
        $this->assertPrimary($this->employee, null, 'cancelled: only those who may edit reopen it');
    }

    #[Test]
    public function the_properties_status_pill_names_the_tasks_current_status(): void
    {
        // An ActionGroup never falls back to the schema's record by itself; without the
        // explicit record the pill read the generic field label instead of the status.
        // The text of the pill button alone (the control's own field label sits above it).
        $pill = fn (): string => trim((string) preg_replace('/\s+/u', ' ', strip_tags(
            (string) preg_replace('/^.*?id="infolist\.statusControl".*?<button(.*?)<\/button>.*$/su', '<button$1', $this->page($this->admin)->html()),
        )));

        $this->assertSame(TaskStatus::Pending->getLabel(), $pill(), 'the pill must name the status, not fall back to the field label');

        $this->tasks->start($this->task, $this->employee);

        $this->assertSame(TaskStatus::InProgress->getLabel(), $pill());
    }

    #[Test]
    public function the_more_menu_holds_the_rest_each_behind_its_own_policy_verb(): void
    {
        $expected = [
            'assigner' => [$this->admin, ['start', 'complete', 'postUpdate', 'checklist', 'comment', 'cancel', 'assign', 'edit', 'delete']],
            'handed-out assignee' => [$this->employee, ['start', 'complete', 'postUpdate', 'checklist', 'comment']],
            'mentioned participant' => [$this->participant, ['comment']],
            'read-only' => [$this->reader, ['comment']],
        ];

        $all = ['start', 'complete', 'postUpdate', 'checklist', 'comment', 'cancel', 'assign', 'edit', 'delete', 'restore'];

        foreach ($expected as $who => [$user, $visible]) {
            $page = $this->page($user)->assertSee(__('tasks.page_ux.actions.more'));

            foreach ($all as $action) {
                in_array($action, $visible, true)
                    ? $page->assertActionVisible($action)
                    : $page->assertActionHidden($action);
            }

            $this->assertNotEmpty($who);
        }

        app()->setLocale('ar');
        $this->page($this->admin)->assertSee('المزيد')->assertSee('بدء مع ملاحظة');
    }

    #[Test]
    public function the_primary_buttons_move_the_task_in_one_click_through_the_service(): void
    {
        Notifications::fake();

        $this->page($this->employee)
            ->callAction('statusStart')
            ->assertNotified(__('tasks.notifications.started'))
            ->assertDispatched(TaskActions::CHANGED_EVENT);

        $this->assertSame(TaskStatus::InProgress, $this->task->refresh()->status);
        $this->assertSame(1, $this->task->updates()->where('kind', TaskUpdateKind::Progress->value)->count(), 'the start is in the progress log');

        // Three open checklist items: the D-25 warning comes first.
        $this->page($this->employee)
            ->mountAction('statusComplete')
            ->assertSchemaComponentExists('checklistWarning')
            ->callMountedAction()
            ->assertNotified(__('tasks.notifications.completed'));

        $this->assertSame(TaskStatus::Completed, $this->task->refresh()->status);
        Notifications::assertSentTo($this->admin, TaskCompletedNotification::class);

        $this->page($this->employee)
            ->callAction('reopen')
            ->assertNotified(__('tasks.notifications.reopened'));

        $this->assertSame(TaskStatus::Pending, $this->task->refresh()->status, 'reopened');
    }

    #[Test]
    public function the_header_refuses_forged_moves_and_actions(): void
    {
        foreach ([$this->participant, $this->reader] as $user) {
            foreach (['statusStart', 'statusComplete', 'start', 'complete', 'postUpdate', 'checklist', 'cancel', 'edit', 'delete', 'assign'] as $action) {
                $this->page($user)
                    ->call('mountAction', $action)
                    ->assertActionNotMounted($action);
            }
        }

        foreach (['cancel', 'edit', 'delete', 'assign'] as $action) {
            $this->page($this->employee)
                ->call('mountAction', $action)
                ->assertActionNotMounted($action);
        }

        $this->assertSame(TaskStatus::Pending, $this->task->refresh()->status);
        $this->assertNull($this->task->deleted_at);

        $this->actingAs($this->outsider)->get(TaskResource::getUrl('view', ['record' => $this->task]))->assertNotFound();
    }

    // --- The checklist, ticked with one click (D-25) ---

    #[Test]
    public function whoever_reports_on_the_task_ticks_an_item_with_one_click(): void
    {
        [$print, $book] = $this->items();

        $this->page($this->employee)
            ->assertSeeHtml('role="checkbox"')
            ->assertSeeHtml('aria-checked="false"')
            ->assertDontSee(__('tasks.page_ux.actions.edit_checklist'))
            ->callAction('tickChecklistItem', arguments: ['item' => $print->getKey(), 'done' => true])
            ->assertSee(__('tasks.fields.checklist_ticked', ['name' => $this->employee->name, 'date' => '2026-10-07 10:00']))
            ->assertSeeHtml('aria-checked="true"')
            ->assertSee('1/3');

        $this->assertSame($this->employee->getKey(), $print->refresh()->completed_by);
        $this->assertTrue(Activity::query()->where('description', ActivityLogEvent::TaskChecklistTicked->value)->where('subject_id', $this->task->getKey())->exists(), 'the tick is audited');

        // The assigner ticks too, and unticks: only the named item changes.
        $this->page($this->admin)
            ->assertSee(__('tasks.page_ux.actions.edit_checklist'))
            ->callAction('tickChecklistItem', arguments: ['item' => (string) $book->getKey(), 'done' => 'true'])
            ->callAction('tickChecklistItem', arguments: ['item' => $print->getKey(), 'done' => false]);

        $this->assertNull($print->refresh()->completed_at);
        $this->assertSame($this->admin->getKey(), $book->refresh()->completed_by);
    }

    #[Test]
    public function a_participant_or_reader_sees_the_checklist_but_every_tick_is_refused(): void
    {
        [$print] = $this->items();

        foreach ([$this->participant, $this->reader] as $user) {
            $this->page($user)
                ->assertSee('Print the brochure')
                ->assertDontSeeHtml('role="checkbox"')
                ->assertSeeHtml('data-state="open"')
                ->assertSee(__('tasks.page_ux.helpers.checklist_view_only'))
                ->assertDontSee(__('tasks.page_ux.actions.edit_checklist'))
                ->callAction('tickChecklistItem', arguments: ['item' => $print->getKey(), 'done' => true])
                ->assertNotified(__('tasks.page_ux.notifications.checklist_refused'));
        }

        $this->assertNull($print->refresh()->completed_at);
        $this->assertFalse(Activity::query()->where('description', ActivityLogEvent::TaskChecklistTicked->value)->exists());
    }

    #[Test]
    public function a_closed_or_deleted_tasks_checklist_is_frozen_on_the_page(): void
    {
        [$print] = $this->items();

        // The page was open with live boxes when someone else completed the task: the
        // click is refused with the reason and the re-render puts the box back, frozen.
        $open = $this->page($this->employee)->assertSeeHtml('role="checkbox"');
        $this->tasks->complete($this->task, $this->admin);

        $open->callAction('tickChecklistItem', arguments: ['item' => $print->getKey(), 'done' => true])
            ->assertNotified(__('tasks.validation.checklist_not_open'))
            ->assertDontSeeHtml('role="checkbox"')
            ->assertSeeHtml('data-state="open"');

        foreach ([$this->admin, $this->employee] as $user) {
            $this->page($user)
                ->assertDontSeeHtml('role="checkbox"')
                ->assertSee(__('tasks.helpers.checklist_frozen'))
                ->assertDontSee(__('tasks.page_ux.actions.edit_checklist'))
                ->callAction('tickChecklistItem', arguments: ['item' => $print->getKey(), 'done' => true])
                ->assertNotified(__('tasks.validation.checklist_not_open'));
        }

        $this->tasks->reopen($this->task, $this->admin);
        $this->task->delete();

        $this->page($this->admin)
            ->assertDontSeeHtml('role="checkbox"')
            ->callAction('tickChecklistItem', arguments: ['item' => $print->getKey(), 'done' => true]);

        $this->assertNull($print->refresh()->completed_at);
    }

    #[Test]
    public function a_tick_never_reaches_another_tasks_item(): void
    {
        $other = $this->tasks->create([
            'title' => 'Another job',
            'assignee_id' => $this->employee->getKey(),
            'checklist' => [['body' => 'Not yours']],
        ], $this->admin);
        $foreign = TaskChecklistItem::query()->where('task_id', $other->getKey())->sole();

        $this->page($this->employee)
            ->callAction('tickChecklistItem', arguments: ['item' => $foreign->getKey(), 'done' => true])
            ->callAction('tickChecklistItem', arguments: ['item' => 'not-an-id', 'done' => true]);

        $this->assertNull($foreign->refresh()->completed_at);
        $this->assertSame(0, TaskChecklistItem::query()->where('task_id', $this->task->getKey())->whereNotNull('completed_at')->count());
    }

    // --- The properties panel ---

    #[Test]
    public function the_status_pill_offers_only_the_readers_moves(): void
    {
        $pill = static fn (string $name): TestAction => TestAction::make($name)->schemaComponent('statusControl', schema: 'infolist');

        $this->page($this->admin)
            ->assertActionVisible($pill('statusStart'))
            ->assertActionVisible($pill('statusComplete'))
            ->assertActionVisible($pill('statusCancel'))
            ->callAction($pill('statusCancel'))
            ->assertNotified(__('tasks.notifications.cancelled'));

        $this->assertSame(TaskStatus::Cancelled, $this->task->refresh()->status);
        $this->tasks->reopen($this->task, $this->admin);

        $this->page($this->employee)
            ->assertActionVisible($pill('statusStart'))
            ->assertActionHidden($pill('statusCancel'))
            ->call('mountAction', 'statusCancel', [], ['schemaComponent' => 'infolist.statusControl'])
            ->assertActionNotMounted('statusCancel');

        $this->assertSame(TaskStatus::Pending, $this->task->refresh()->status);

        // A reader with no move sees the plain pill: the status, no menu; a forged move is refused.
        foreach ([$this->participant, $this->reader] as $user) {
            $this->page($user)
                ->assertSchemaComponentHidden('statusControl', 'infolist')
                ->assertSchemaComponentVisible('status', 'infolist')
                ->assertSee(TaskStatus::Pending->getLabel())
                ->call('mountAction', 'statusStart', [], ['schemaComponent' => 'infolist.statusControl'])
                ->assertActionNotMounted('statusStart');
        }

        $this->assertSame(TaskStatus::Pending, $this->task->refresh()->status);
    }

    #[Test]
    public function only_those_who_may_edit_move_the_due_date_with_the_chips(): void
    {
        $chip = static fn (string $name): TestAction => TestAction::make($name)->schemaComponent('rescheduleChips', schema: 'infolist');

        $this->page($this->admin)
            ->assertActionVisible($chip('rescheduleToday'))
            ->callAction($chip('rescheduleTomorrow'))
            ->assertHasNoErrors();

        // Tomorrow on the organisation calendar, at the task's own time of day.
        $this->assertSame('2026-10-08 09:00', $this->task->refresh()->due_at?->format('Y-m-d H:i'));

        // Every chip refused, so the whole row is gone; the request a browser would send is refused too.
        foreach ([$this->employee, $this->participant, $this->reader] as $user) {
            $this->page($user)
                ->assertSchemaComponentHidden('rescheduleChips', 'infolist')
                ->call('mountAction', 'rescheduleNextWeek', [], ['schemaComponent' => 'infolist.rescheduleChips'])
                ->assertActionNotMounted('rescheduleNextWeek');

            $this->actingAs($user);
            $this->assertFalse(TaskActions::rescheduleTo(DueShortcut::NextWeek)->record($this->task)->isAuthorized());
        }

        $this->assertSame('2026-10-08 09:00', $this->task->refresh()->due_at?->format('Y-m-d H:i'), 'the dates stay with the assigner (D-14)');
    }

    #[Test]
    public function the_properties_show_the_people_the_due_date_and_the_priority(): void
    {
        $this->page($this->employee)
            ->assertSee(__('tasks.page_ux.sections.properties'))
            ->assertSee($this->employee->name)
            ->assertSee($this->admin->name)
            ->assertSeeHtml('class="crm-person"')
            ->assertSeeHtml('datetime="2026-10-10T09:00:00+03:00"')
            ->assertSeeHtml('crm-flag');
    }

    #[Test]
    public function the_routine_link_opens_only_for_those_who_manage_routines(): void
    {
        $template = TaskTemplate::factory()->assignedBy($this->admin)->create(['title' => 'Weekly stock count']);
        $this->task->forceFill(['template_id' => $template->getKey()])->save();
        $url = TaskTemplateResource::getUrl('edit', ['record' => $template]);

        $this->page($this->admin)
            ->assertSee('Weekly stock count')
            ->assertSeeHtml($url);

        $this->page($this->employee)
            ->assertSee('Weekly stock count')
            ->assertDontSeeHtml($url);
    }

    // --- The composer ---

    #[Test]
    public function the_composer_offers_the_progress_mode_only_to_whoever_may_report(): void
    {
        $this->composer($this->admin)->assertFormFieldVisible('mode')->assertFormFieldVisible('comment');
        $this->composer($this->employee)->assertFormFieldVisible('mode');

        foreach ([$this->participant, $this->reader] as $user) {
            $this->composer($user)
                ->assertFormFieldHidden('mode')
                ->assertFormFieldVisible('comment')
                ->assertFormFieldHidden('body');
        }

        // Open composer, then the task is completed elsewhere: told so, it drops the progress mode.
        $open = $this->composer($this->employee)->assertFormFieldVisible('mode');

        $this->tasks->complete($this->task, $this->employee);

        $open->dispatch(TaskActions::CHANGED_EVENT, task: (int) $this->task->getKey())
            ->assertFormFieldHidden('mode')
            ->assertFormFieldVisible('comment');

        $this->composer($this->employee)->assertFormFieldHidden('mode')->assertFormFieldVisible('comment');
    }

    #[Test]
    public function a_comment_from_the_composer_mentions_a_colleague_and_pulls_them_in(): void
    {
        Notifications::fake();
        $colleague = $this->makeUser(CrmRole::Employee, ['name' => 'Omar Employee']);

        $this->assertFalse($colleague->can('view', $this->task), 'precondition: not yet a participant');

        $this->composer($this->employee)
            ->fillForm(['comment' => $this->editorDocument(
                ['type' => 'text', 'text' => 'Please check the shelves, '],
                ['type' => 'mention', 'attrs' => ['id' => (string) $colleague->getKey(), 'label' => 'Forged Label', 'char' => '@']],
            )])
            ->callAction('post')
            ->assertHasNoFormErrors()
            ->assertNotified(__('tasks.notifications.comment_posted'))
            ->assertDispatched(TaskActions::CHANGED_EVENT);

        $comment = $this->task->updates()->latest('id')->firstOrFail();

        $this->assertTrue($comment->isComment());
        $this->assertSame('Please check the shelves, @Omar Employee', $comment->body);
        $this->assertTrue(TaskUpdateMention::query()->where('task_update_id', $comment->getKey())->where('user_id', $colleague->getKey())->exists());
        Notifications::assertSentTo($colleague, TaskMentionNotification::class);
        $this->assertTrue($colleague->refresh()->can('view', $this->task->refresh()), 'the mention opens the task (D-20)');
        $this->assertFalse($colleague->can('progress', $this->task), 'and nothing more');
    }

    #[Test]
    public function the_composer_refuses_an_empty_or_too_long_comment_and_a_forged_mention(): void
    {
        $this->composer($this->employee)
            ->fillForm(['comment' => $this->editorDocument(['type' => 'mention', 'attrs' => ['id' => '999999', 'label' => 'Ghost', 'char' => '@']])])
            ->callAction('post')
            ->assertHasFormErrors(['comment' => 'required']);

        $this->composer($this->employee)
            ->fillForm(['comment' => $this->editorDocument(['type' => 'text', 'text' => str_repeat('x', TaskService::COMMENT_TEXT_MAX + 1)])])
            ->callAction('post')
            ->assertHasFormErrors(['comment' => 'max']);

        $this->assertSame(1, $this->task->updates()->count(), 'only the setup comment');
    }

    #[Test]
    public function a_progress_update_from_the_composer_goes_through_the_service_and_tells_the_assigner(): void
    {
        Notifications::fake();

        $this->composer($this->employee)
            ->fillForm(['mode' => TaskComposer::MODE_PROGRESS])
            ->assertFormFieldVisible('body')
            ->assertFormFieldHidden('comment')
            ->fillForm(['body' => 'Half of the shelves counted'])
            ->callAction('post')
            ->assertHasNoFormErrors()
            ->assertNotified(__('tasks.notifications.update_posted'));

        $update = $this->task->updates()->latest('id')->firstOrFail();

        $this->assertSame(TaskUpdateKind::Progress, $update->kind);
        $this->assertSame('Half of the shelves counted', $update->body);
        Notifications::assertSentTo($this->admin, TaskProgressNotification::class);

        $this->composer($this->employee)
            ->fillForm(['mode' => TaskComposer::MODE_PROGRESS, 'body' => ''])
            ->callAction('post')
            ->assertHasFormErrors(['body' => 'required']);
    }

    #[Test]
    public function a_participant_cannot_forge_the_progress_mode(): void
    {
        $this->composer($this->participant)
            ->set('data.mode', TaskComposer::MODE_PROGRESS)
            ->set('data.body', 'Forged progress')
            ->callAction('post')
            ->assertHasNoFormErrors()
            ->assertNotified(__('tasks.validation.update_not_allowed'))
            ->assertNotDispatched(TaskActions::CHANGED_EVENT);

        $this->assertSame(0, $this->task->updates()->where('kind', TaskUpdateKind::Progress->value)->count());
        $this->assertSame(1, $this->task->updates()->count(), 'nothing posted, not even as a comment');

        // A read-only user comments (D-17), nothing more.
        $this->composer($this->reader)
            ->fillForm(['comment' => $this->editorDocument(['type' => 'text', 'text' => 'Noted'])])
            ->callAction('post')
            ->assertHasNoFormErrors();

        $this->assertSame('Noted', $this->task->updates()->latest('id')->firstOrFail()->body);
    }

    #[Test]
    public function a_progress_update_written_while_the_task_closes_is_refused_with_the_reason_and_kept(): void
    {
        // Typed as a person types: each change reaches the server and keeps the draft.
        $open = $this->composer($this->employee)
            ->set('data.mode', TaskComposer::MODE_PROGRESS)
            ->set('data.body', 'My progress');

        // The assigner completes the task while the employee is still writing.
        $this->tasks->complete($this->task, $this->admin);

        $open->callAction('post')
            ->assertHasNoFormErrors()
            ->assertNotified(__('tasks.validation.update_not_open'))
            ->assertNotDispatched(TaskActions::CHANGED_EVENT)
            // The text stays on screen, read-only, and the switch offers the comment instead.
            ->assertSchemaStateSet(['mode' => TaskComposer::MODE_PROGRESS, 'body' => 'My progress'])
            ->assertFormFieldVisible('body')
            ->assertFormFieldDisabled('body')
            ->assertFormFieldHidden('comment')
            ->assertFormFieldVisible('mode');

        $this->assertSame(0, $this->task->updates()->where('kind', TaskUpdateKind::Progress->value)->whereNotNull('body')->where('body', 'My progress')->count(), 'nothing was posted');
        $this->assertSame(0, $this->task->updates()->where('kind', TaskUpdateKind::Comment->value)->where('user_id', $this->employee->getKey())->count(), 'nor turned into a comment');

        // The kept draft comes back the same way, and switching to a comment posts one.
        $this->composer($this->employee)
            ->assertSchemaStateSet(['mode' => TaskComposer::MODE_PROGRESS, 'body' => 'My progress'])
            ->assertFormFieldDisabled('body')
            ->fillForm(['mode' => TaskComposer::MODE_COMMENT])
            ->assertFormFieldVisible('comment')
            ->assertFormFieldHidden('body')
            ->fillForm(['comment' => $this->editorDocument(['type' => 'text', 'text' => 'Done before I could report'])])
            ->callAction('post')
            ->assertHasNoFormErrors()
            ->assertNotified(__('tasks.notifications.comment_posted'));

        $this->assertSame('Done before I could report', $this->task->updates()->latest('id')->firstOrFail()->body);
    }

    #[Test]
    public function the_composer_stays_closed_on_a_deleted_task_and_to_an_outsider(): void
    {
        $this->task->delete();

        $this->composer($this->admin)
            ->assertDontSeeHtml('crm-composer__footer')
            ->call('mountAction', 'post')
            ->assertActionNotMounted('post');

        $this->page($this->admin)->assertDontSeeHtml('crm-composer__footer');
        $this->assertSame(1, $this->task->updates()->count());

        $this->task->restore();

        Livewire::actingAs($this->outsider)
            ->test(TaskComposer::class, ['record' => $this->task])
            ->assertForbidden();
    }

    #[Test]
    public function the_draft_survives_leaving_the_page_for_its_writer_only_and_a_post_clears_it(): void
    {
        $this->composer($this->employee)
            ->set('data.mode', TaskComposer::MODE_PROGRESS)
            ->set('data.body', 'Counting the second shelf');

        $this->composer($this->employee)
            ->assertSchemaStateSet(['mode' => TaskComposer::MODE_PROGRESS, 'body' => 'Counting the second shelf'])
            ->callAction('post')
            ->assertHasNoFormErrors();

        $this->composer($this->employee)->assertSchemaStateSet(['mode' => TaskComposer::MODE_COMMENT, 'body' => null]);
        $this->assertSame(TaskUpdateKind::Progress, $this->task->updates()->latest('id')->firstOrFail()->kind);

        // Another writer never sees it; a stale progress draft becomes a comment once reporting is closed.
        $this->composer($this->admin)->set('data.body', 'The assigner\'s note');
        $this->composer($this->participant)->assertSchemaStateSet(['mode' => TaskComposer::MODE_COMMENT, 'body' => null]);
    }

    // --- The activity: filter and refresh ---

    #[Test]
    public function the_thread_filters_comments_and_progress_updates_newest_first(): void
    {
        $this->tasks->start($this->task, $this->employee, 'Leaving now');
        $this->travel(5)->minutes();
        $this->tasks->comment($this->task, $this->admin, 'Bring the price list');

        $this->page($this->admin)
            ->assertSeeHtmlInOrder(['Bring the price list', 'Leaving now', 'please help'])
            ->call('setThreadFilter', ViewTask::THREAD_COMMENTS)
            ->assertSee('Bring the price list')
            ->assertDontSee('Leaving now')
            ->call('setThreadFilter', ViewTask::THREAD_UPDATES)
            ->assertSee('Leaving now')
            ->assertDontSee('Bring the price list')
            ->call('setThreadFilter', 'anything else')
            ->assertSet('threadFilter', ViewTask::THREAD_ALL)
            ->set('threadFilter', 'forged')
            ->assertSet('threadFilter', ViewTask::THREAD_ALL)
            ->assertSee('Leaving now');

        $plain = $this->tasks->create(['title' => 'Quiet task', 'assignee_id' => $this->admin->getKey()], $this->admin);

        $this->page($this->admin, $plain)
            ->assertSee(__('tasks.empty.thread'))
            ->call('setThreadFilter', ViewTask::THREAD_UPDATES)
            ->assertSee(__('tasks.page_ux.empty.thread_updates'));
    }

    #[Test]
    public function the_page_re_renders_its_thread_when_the_composer_or_the_undo_changed_the_task(): void
    {
        $page = $this->page($this->admin)->assertDontSee('Posted from the composer');

        $this->tasks->comment($this->task, $this->employee, 'Posted from the composer');

        $page->dispatch(TaskActions::CHANGED_EVENT, task: (int) $this->task->getKey())
            ->assertSee('Posted from the composer');
    }

    // --- Layout, both locales, cost, separation from Sales ---

    #[Test]
    public function the_page_renders_in_two_columns_in_both_locales_for_every_reader(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            foreach ([$this->admin, $this->employee, $this->participant, $this->reader] as $user) {
                $this->page($user)
                    ->assertOk()
                    ->assertSeeHtml('class="crm-task-page"')
                    ->assertSeeHtml('class="crm-task-page__content"')
                    ->assertSeeHtml('class="crm-task-page__aside"')
                    ->assertSeeHtml('class="crm-task-page__activity"')
                    ->assertSee(__('tasks.page_ux.sections.properties'))
                    ->assertSee(__('tasks.page_ux.filters.comments'))
                    ->assertSee(__('tasks.sections.thread'))
                    ->assertSee('Prepare the visit')
                    ->assertSee('Bring the <b>samples</b>')
                    ->assertDontSeeHtml('<b>samples</b>')
                    ->assertSeeHtmlInOrder(['crm-task-page__content', 'crm-task-page__aside', 'crm-task-page__activity']);
            }
        }
    }

    /**
     * The HTTP walk per role: the page, its composer and its files render
     * for every reader who may open the task, and an outsider gets 404.
     */
    #[Test]
    #[DataProvider('readers')]
    public function the_page_answers_each_reader_over_http(string $who, int $status): void
    {
        $user = match ($who) {
            'admin' => $this->admin,
            'employee' => $this->employee,
            'participant' => $this->participant,
            'reader' => $this->reader,
            default => $this->outsider,
        };

        $response = $this->actingAs($user)->get(TaskResource::getUrl('view', ['record' => $this->task]))->assertStatus($status);

        if ($status === 200) {
            $response->assertSee('Prepare the visit')->assertSee('crm-composer__footer', escape: false);
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function readers(): array
    {
        return [
            'assigner' => ['admin', 200],
            'handed-out assignee' => ['employee', 200],
            'mentioned participant' => ['participant', 200],
            'read-only' => ['reader', 200],
            'outsider' => ['outsider', 404],
        ];
    }

    #[Test]
    public function the_page_costs_no_query_per_thread_entry_or_checklist_item(): void
    {
        // One item ticked already, so who ticked is loaded from the start.
        $this->tasks->tickChecklist($this->task, $this->employee, [(int) $this->items()[0]->getKey()]);

        $render = fn () => $this->page($this->admin)->assertOk();
        $render();

        $small = $this->queriesDuring($render);

        for ($i = 0; $i < 12; $i++) {
            $this->tasks->comment($this->task, $i % 2 === 0 ? $this->employee : $this->admin, "Entry {$i}");
            $this->checklistItemOn($this->task, "Step {$i}", $i % 2 === 0 ? now() : null);
        }

        $large = $this->queriesDuring($render);

        $this->assertLessThanOrEqual(count($small), count($large), sprintf("ViewTask: %d queries before, %d after 12 entries and 12 items.\n%s", count($small), count($large), $this->topRepeated($large)));
    }

    #[Test]
    public function the_sales_task_tabs_keep_the_classic_task_modal(): void
    {
        $this->actingAs($this->admin);

        $page = $this->keys(TaskInfolist::configure(Schema::make())->getComponents(withActions: false, withHidden: true));
        $tab = $this->keys(TaskInfolist::configureForSubjectTab(Schema::make())->getComponents(withActions: false, withHidden: true));

        foreach (['statusControl', 'rescheduleChips', 'taskComposer', 'taskFiles', 'page_checklist', 'pageThread'] as $key) {
            $this->assertContains($key, $page, "the workspace page holds {$key}");
            $this->assertNotContains($key, $tab, "the Sales modal never holds {$key}");
        }

        $this->assertContains('updates', $tab, 'the Sales modal keeps its classic thread');
    }

    // --- Helpers ---

    private function page(User $user, ?Task $task = null): Testable
    {
        return Livewire::actingAs($user)->test(ViewTask::class, ['record' => ($task ?? $this->task)->getKey()]);
    }

    private function composer(User $user): Testable
    {
        return Livewire::actingAs($user)->test(TaskComposer::class, ['record' => $this->task->refresh()]);
    }

    /** Asserts which of the three primary buttons shows — at most one, ever. */
    private function assertPrimary(User $user, ?string $expected, string $case): void
    {
        $page = $this->page($user);
        $this->task->refresh();

        foreach (['statusStart', 'statusComplete', 'reopen'] as $primary) {
            $primary === $expected
                ? $page->assertActionVisible($primary)
                : $page->assertActionHidden($primary);
        }

        $this->assertNotEmpty($case);
    }

    /**
     * @return list<TaskChecklistItem>
     */
    private function items(): array
    {
        return TaskChecklistItem::query()->where('task_id', $this->task->getKey())->orderBy('position')->get()->all();
    }

    /**
     * @param  array<mixed>  ...$inline
     * @return array<string, mixed>
     */
    private function editorDocument(array ...$inline): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $inline]]];
    }

    /**
     * Every component key in the tree.
     *
     * @param  array<mixed>  $components
     * @return list<string>
     */
    private function keys(array $components): array
    {
        $keys = [];

        foreach ($components as $component) {
            if (! $component instanceof Component) {
                continue;
            }

            if ($component instanceof Actions || $component instanceof LivewireComponent) {
                $keys[] = (string) $component->getKey(isAbsolute: false);
            }

            if ($component instanceof Entry) {
                $keys[] = $component->getName();
            }

            $children = $component->getDefaultChildComponents();
            $keys = [...$keys, ...$this->keys($children)];
        }

        return $keys;
    }
}
