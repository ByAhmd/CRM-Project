<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\DueShortcut;
use App\Enums\MyWorkView;
use App\Enums\Permission;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Exceptions\Access\UnassignableUserException;
use App\Filament\Pages\TasksBoard;
use App\Filament\Pages\TasksDashboard;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\DealTasksRelationManager;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Support\OwnerSelect;
use App\Filament\Support\TaskActions;
use App\Filament\Support\TaskVisuals;
use App\Filament\Widgets\UpcomingFollowUpsWidget;
use App\Models\Deal;
use App\Models\Export;
use App\Models\Task;
use App\Models\User;
use App\Notifications\RecordAssignedNotification;
use App\Services\Tasks\TaskService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity as AuditEntry;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Support\TaskActionsHost;
use Tests\TestCase;

/**
 * The screens of shared tasks (decision D-28) in the Tasks workspace. The
 * workspace form names every assignee at once — `assignee_ids`, the first
 * one first, for holders of `task.assign`; a read-only list for everyone
 * else, whose forged list changes nothing and is refused by the service.
 * Quick add shares a task the same way and carries the list to the full
 * form. Every screen shows who holds a task in one presentation (avatars,
 * the first assignee's name, «+N» naming the others), «مهامي» and the
 * filters find a task shared with the reader, the export lists the other
 * assignees, and the Sales task tabs keep their single owner field (D-27).
 */
final class SharedTaskScreensTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $admin;

    private User $huda;

    private User $omar;

    private User $sara;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        // Thursday 2026-10-15, 09:00 in Riyadh (the organisation clock).
        $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));
        config()->set('mail.default', 'log');
        app()->setLocale('en');

        $this->admin = $this->admin();
        // Distinct names, so every name on a screen points at exactly one person.
        $this->huda = $this->makeUser(CrmRole::Employee, ['name' => 'Huda Salem']);
        $this->omar = $this->makeUser(CrmRole::Employee, ['name' => 'Omar Fahad']);
        $this->sara = $this->makeUser(CrmRole::Employee, ['name' => 'Sara Nasser']);
        $this->tasks = app(TaskService::class);
    }

    // --- The workspace form ---

    #[Test]
    public function an_administrator_hands_a_new_task_to_three_employees_through_the_workspace_form(): void
    {
        Notification::fake();

        Livewire::actingAs($this->admin)
            ->test(CreateTask::class)
            ->assertFormFieldDoesNotExist(OwnerSelect::FIELD)
            ->assertFormFieldExists(TaskForm::ASSIGNEES, function (Select $field): bool {
                $options = $field->getOptions();

                return $field->isMultiple()
                    && $field->isSearchable()
                    && ! $field->isNative()
                    && $field->getLabel() === __('tasks.fields.assignees')
                    && ($options[$this->huda->getKey()] ?? null) === 'Huda Salem'
                    && ($options[$this->sara->getKey()] ?? null) === 'Sara Nasser';
            })
            ->assertSee(__('tasks.helpers.assignees'))
            ->assertSchemaStateSet([TaskForm::ASSIGNEES => [$this->admin->getKey()]])
            ->fillForm([
                'title' => 'Count the stock',
                TaskForm::ASSIGNEES => [$this->huda->getKey(), $this->omar->getKey(), $this->sara->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $task = Task::query()->where('title', 'Count the stock')->sole();

        $this->assertSame($this->huda->getKey(), (int) $task->assignee_id, 'the first chosen is the one the task is filed under');
        $this->assertSame([$this->huda->getKey(), $this->omar->getKey(), $this->sara->getKey()], $task->assigneeIds());
        $this->assertSame($this->admin->getKey(), (int) $task->assigned_by);

        foreach ([$this->huda, $this->omar, $this->sara] as $employee) {
            Notification::assertSentToTimes($employee, RecordAssignedNotification::class, 1);
        }
    }

    #[Test]
    public function the_edit_form_shows_every_assignee_first_one_first_and_saves_the_new_list(): void
    {
        $task = $this->handOut('Inventory', $this->huda, $this->omar);
        Notification::fake();

        Livewire::actingAs($this->admin)
            ->test(EditTask::class, ['record' => $task->getRouteKey()])
            ->assertSchemaStateSet([TaskForm::ASSIGNEES => [$this->huda->getKey(), $this->omar->getKey()]])
            ->fillForm([TaskForm::ASSIGNEES => [$this->sara->getKey(), $this->huda->getKey()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$this->sara->getKey(), $this->huda->getKey()], $task->refresh()->assigneeIds());
        $this->assertSame($this->sara->getKey(), (int) $task->assignee_id);
        Notification::assertSentTo($this->sara, RecordAssignedNotification::class);
        Notification::assertNotSentTo($this->omar, RecordAssignedNotification::class);

        $entry = AuditEntry::query()->where('description', ActivityLogEvent::TaskAssigneesChanged->value)->latest('id')->firstOrFail();
        $this->assertSame([$this->omar->name], $entry->getExtraProperty('assignees_removed'));
    }

    #[Test]
    public function a_colleague_disabled_since_stays_on_the_list_so_an_unrelated_edit_still_saves(): void
    {
        $task = $this->handOut('With a colleague on leave', $this->huda, $this->sara);
        $this->sara->forceFill(['status' => UserStatus::Disabled])->save();

        Livewire::actingAs($this->admin)
            ->test(EditTask::class, ['record' => $task->getRouteKey()])
            ->assertFormFieldExists(TaskForm::ASSIGNEES, fn (Select $field): bool => ($field->getOptions()[$this->sara->getKey()] ?? null) === 'Sara Nasser')
            ->fillForm(['title' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed', $task->refresh()->title);
        $this->assertSame([$this->huda->getKey(), $this->sara->getKey()], $task->assigneeIds());

        // A disabled colleague is offered for keeping only: a new task cannot be handed to them.
        Livewire::actingAs($this->admin)
            ->test(CreateTask::class)
            ->assertFormFieldExists(TaskForm::ASSIGNEES, fn (Select $field): bool => ! array_key_exists($this->sara->getKey(), $field->getOptions()));
    }

    #[Test]
    public function without_task_assign_the_form_lists_every_assignee_read_only_and_a_forged_list_changes_nothing(): void
    {
        $support = $this->support();
        $this->assertFalse($support->can(Permission::TaskAssign->value));
        $task = $this->handOut('Support may edit, not share', $this->huda, $this->omar);

        Livewire::actingAs($support)
            ->test(EditTask::class, ['record' => $task->getRouteKey()])
            ->assertFormFieldHidden(TaskForm::ASSIGNEES)
            ->assertSeeHtml('<span class="crm-person__name">Huda Salem</span>')
            ->assertSeeHtml('<span class="crm-person__name">Omar Fahad</span>')
            ->fillForm(['title' => 'Edited by support'])
            ->set('data.'.TaskForm::ASSIGNEES, [(string) $this->sara->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Edited by support', $task->refresh()->title);
        $this->assertSame([$this->huda->getKey(), $this->omar->getKey()], $task->assigneeIds(), 'the forged list never reaches the service');

        // The service refuses the same list on its own: sharing is handing out (D-28).
        try {
            $this->tasks->update($task, [TaskForm::ASSIGNEES => [$this->huda->getKey(), $this->sara->getKey()]], $support);
            $this->fail('a user without task.assign changed who holds the task');
        } catch (UnassignableUserException) {
            // Refused before anything is written.
        }

        $this->assertSame([$this->huda->getKey(), $this->omar->getKey()], $task->refresh()->assigneeIds());

        // An employee's new task is theirs alone, whatever list the browser sends.
        Livewire::actingAs($this->huda)
            ->test(CreateTask::class)
            ->assertFormFieldHidden(TaskForm::ASSIGNEES)
            ->assertSeeHtml('<span class="crm-person__name">Huda Salem</span>')
            ->fillForm(['title' => 'My own list'])
            ->set('data.'.TaskForm::ASSIGNEES, [(string) $this->omar->getKey(), (string) $this->sara->getKey()])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame([$this->huda->getKey()], Task::query()->where('title', 'My own list')->sole()->assigneeIds());

        $this->expectException(UnassignableUserException::class);
        $this->tasks->create(['title' => 'Forged', TaskForm::ASSIGNEES => [$this->huda->getKey(), $this->omar->getKey()]], $this->huda);
    }

    // --- Quick add ---

    #[Test]
    public function quick_add_shares_a_new_task_and_more_options_carries_every_assignee_to_the_full_form(): void
    {
        Notification::fake();

        // The dialog opens with the reader as the one assignee.
        $this->host($this->admin)
            ->mountAction('quickAdd')
            ->assertFormFieldExists(TaskForm::ASSIGNEES, fn (Select $field): bool => $field->isMultiple() && $field->getState() == [$this->admin->getKey()]);

        $this->host($this->admin)->callAction('quickAdd', data: [
            'title' => 'Prepare the samples',
            TaskForm::ASSIGNEES => [$this->huda->getKey(), $this->omar->getKey(), $this->sara->getKey()],
            'due_shortcut' => DueShortcut::None->value,
            'priority' => TaskPriority::Medium->value,
        ])->assertHasNoErrors()->assertNotified(__('tasks.notifications.added'));

        $task = Task::query()->where('title', 'Prepare the samples')->sole();
        $this->assertSame([$this->huda->getKey(), $this->omar->getKey(), $this->sara->getKey()], $task->assigneeIds());
        Notification::assertSentTo([$this->huda, $this->omar, $this->sara], RecordAssignedNotification::class);

        // «خيارات أكثر»: the list waits in the session, in the order chosen, and fills the full form.
        $this->host($this->admin)->callAction(TestAction::make('quickAdd')->arguments(['more' => true]), data: [
            'title' => 'Plan the launch',
            TaskForm::ASSIGNEES => [$this->omar->getKey(), $this->huda->getKey()],
            'due_shortcut' => DueShortcut::Tomorrow->value,
            'priority' => TaskPriority::High->value,
        ]);

        $stash = session(TaskActions::PREFILL_SESSION_KEY);
        $this->assertIsArray($stash);
        $this->assertSame([(string) $this->omar->getKey(), (string) $this->huda->getKey()], $stash['values']['assignees'] ?? null);
        $this->assertFalse(Task::query()->where('title', 'Plan the launch')->exists());

        Livewire::withQueryParams([TaskActions::PREFILL_PARAMETER => $stash['token']])
            ->actingAs($this->admin)
            ->test(CreateTask::class)
            ->assertSchemaStateSet(['title' => 'Plan the launch', TaskForm::ASSIGNEES => [$this->omar->getKey(), $this->huda->getKey()]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame([$this->omar->getKey(), $this->huda->getKey()], Task::query()->where('title', 'Plan the launch')->sole()->assigneeIds());

        // Each id is validated as before: malformed and repeated ones dropped, the order kept.
        $this->assertSame(
            [TaskForm::ASSIGNEES => [$this->omar->getKey(), $this->huda->getKey()]],
            TaskActions::createPrefill(['assignees' => ['x', (string) $this->omar->getKey(), '1 OR 1=1', (string) $this->huda->getKey(), (string) $this->omar->getKey(), '-3']], $this->admin),
        );
        $this->assertSame([], TaskActions::createPrefill(['assignees' => (string) $this->huda->getKey()], $this->admin), 'a list, or nothing');

        // Only within the reader's reach, and nothing at all without task.assign.
        $narrow = $this->userWithPermissions(null, [Permission::TaskViewAny, Permission::TaskCreate, Permission::TaskUpdate, Permission::TaskAssign]);
        $this->assertSame(
            [TaskForm::ASSIGNEES => [$narrow->getKey()]],
            TaskActions::createPrefill(['assignees' => [(string) $this->huda->getKey(), (string) $narrow->getKey()]], $narrow),
        );
        $this->assertSame([], TaskActions::createPrefill(['assignees' => [(string) $this->omar->getKey()]], $this->huda));
    }

    // --- «مهامي» and the filters ---

    #[Test]
    public function the_my_tab_and_the_filters_find_a_task_shared_with_the_reader(): void
    {
        $sharedWithAdmin = $this->handOut('Shared with the admin', $this->huda, $this->admin);
        $adminFirst = $this->handOut('The admin first, Omar too', $this->admin, $this->omar);
        $hudaAlone = $this->handOut('Huda alone', $this->huda);
        $omarCo = $this->handOut('Sara first, Omar too', $this->sara, $this->omar);
        $ownToDo = $this->tasks->create(['title' => 'The admin alone'], $this->admin);

        $list = Livewire::actingAs($this->admin)->test(ListTasks::class)->set('activeTab', 'my');

        $list->assertCanSeeTableRecords([$sharedWithAdmin, $adminFirst, $ownToDo])
            ->assertCanNotSeeTableRecords([$hudaAlone, $omarCo]);

        $page = $list->instance();
        $this->assertInstanceOf(ListTasks::class, $page);
        $this->assertSame('3', (string) $page->getTabs()['my']->getBadge(), '«مهامي» counts the task the reader shares');

        // The filters persist in the session, so each one is removed before the next is tried.
        Livewire::actingAs($this->admin)->test(ListTasks::class)->set('activeTab', 'all')
            ->filterTable('mine')
            ->assertCanSeeTableRecords([$sharedWithAdmin, $adminFirst, $ownToDo])
            ->assertCanNotSeeTableRecords([$hudaAlone, $omarCo])
            ->removeTableFilters()
            ->filterTable('assignee_id', $this->omar->getKey())
            ->assertCanSeeTableRecords([$adminFirst, $omarCo])
            ->assertCanNotSeeTableRecords([$sharedWithAdmin, $hudaAlone, $ownToDo])
            ->removeTableFilters()
            // «أسندتُها أنا»: a first assignee the reader named, or a colleague the reader added.
            ->filterTable('handed_out_by_me')
            ->assertCanSeeTableRecords([$sharedWithAdmin, $adminFirst, $hudaAlone, $omarCo])
            ->assertCanNotSeeTableRecords([$ownToDo])
            ->removeTableFilters()
            // The search finds a task by anyone holding it.
            ->searchTable('Omar Fahad')
            ->assertCanSeeTableRecords([$adminFirst, $omarCo])
            ->assertCanNotSeeTableRecords([$sharedWithAdmin, $hudaAlone, $ownToDo])
            ->searchTable(null);

        // A co-assignee opens the list on «مهامي» with the task shared with them in it.
        Livewire::actingAs($this->omar)->test(ListTasks::class)
            ->assertSet('activeTab', 'my')
            ->assertCanSeeTableRecords([$adminFirst, $omarCo]);
    }

    // --- One presentation of who holds a task ---

    #[Test]
    public function the_presentation_shows_three_avatars_the_first_name_and_plus_n_naming_everyone_in_both_languages(): void
    {
        $layla = $this->makeUser(CrmRole::Employee, ['name' => 'Layla Karim']);
        $task = $this->handOut('Stocktake', $this->huda, $this->omar, $this->sara, $layla)->load(Task::ASSIGNEE_RELATIONS);
        $others = ['Omar Fahad', 'Sara Nasser', 'Layla Karim'];

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $html = (string) TaskVisuals::assignees($task);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            $this->assertSame([], $queries, "{$locale}: read from the loaded relations only");
            $this->assertSame(3, substr_count($html, 'class="crm-avatar"'), "{$locale}: three avatars overlap, the rest are counted");
            $this->assertStringContainsString('<span class="crm-assignees__name">Huda Salem</span>', $html);
            // «+N» counts the people whose avatar is not drawn: one beyond the stack, three beside a lone avatar.
            $this->assertStringContainsString('<span class="crm-assignees__more" aria-hidden="true" dir="ltr"', $html);
            $this->assertStringContainsString('<span class="crm-assignees__more-stacked">+1</span>', $html);
            $this->assertStringContainsString('<span class="crm-assignees__more-compact">+3</span>', $html);
            $this->assertStringContainsString(
                e(trans_choice('tasks.assignees.others', 3, ['count' => 3, 'names' => implode(__('tasks.assignees.separator'), $others)])),
                $html,
                "{$locale}: the chip names the others for a screen reader",
            );
            $this->assertStringContainsString(
                'title="'.e(__('tasks.assignees.everyone', ['names' => implode(__('tasks.assignees.separator'), ['Huda Salem', ...$others])])).'"',
                $html,
                "{$locale}: the tooltip lists everyone",
            );
        }

        // The Arabic plural forms of the chip's words.
        app()->setLocale('ar');
        $this->assertSame('ومكلَّف آخر: Omar Fahad', trans_choice('tasks.assignees.others', 1, ['count' => 1, 'names' => 'Omar Fahad']));
        $this->assertSame('ومكلَّفان آخران: Omar Fahad، Sara Nasser', trans_choice('tasks.assignees.others', 2, ['count' => 2, 'names' => 'Omar Fahad، Sara Nasser']));
        $this->assertSame('و3 مكلَّفين آخرين: …', trans_choice('tasks.assignees.others', 3, ['count' => 3, 'names' => '…']));
        $this->assertSame('و11 مكلَّفاً آخر: …', trans_choice('tasks.assignees.others', 11, ['count' => 11, 'names' => '…']));

        app()->setLocale('en');
        $this->assertSame('and 1 other assignee: Omar Fahad', trans_choice('tasks.assignees.others', 1, ['count' => 1, 'names' => 'Omar Fahad']));

        // One holder: no chip; a name is always escaped.
        $alone = $this->handOut('Alone', $this->huda)->load(Task::ASSIGNEE_RELATIONS);
        $this->assertStringNotContainsString('crm-assignees__more', (string) TaskVisuals::assignees($alone));

        $sly = $this->makeUser(CrmRole::Employee, ['name' => '<b>Sly</b>']);
        $escaped = (string) TaskVisuals::assignees($this->handOut('Escaped', $sly, $this->huda)->load(Task::ASSIGNEE_RELATIONS));
        $this->assertStringNotContainsString('<b>Sly</b>', $escaped);
        $this->assertStringContainsString('&lt;b&gt;Sly&lt;/b&gt;', $escaped);

        // Nobody holds it: nothing to draw.
        $this->assertNull(TaskVisuals::assignees($this->tasks->create(['title' => 'Nobody', 'assignee_id' => null], $this->admin)->load(Task::ASSIGNEE_RELATIONS)));
    }

    #[Test]
    public function every_workspace_screen_names_whoever_holds_a_shared_task(): void
    {
        $task = $this->handOut('Count the stock', $this->huda, $this->omar, $this->sara, [
            'kind' => TaskKind::FollowUp->value,
            'due_at' => '2026-10-17 10:00:00',
        ]);
        $everyone = implode(__('tasks.assignees.separator'), ['Huda Salem', 'Omar Fahad', 'Sara Nasser']);

        // The list: the first name, every name in the tooltip; three avatars show everyone, so «+2»
        // is drawn only where the compact cell leaves the first avatar alone.
        Livewire::actingAs($this->admin)->test(ListTasks::class)->set('activeTab', 'all')
            ->assertSeeHtml('<span class="crm-assignees__name">Huda Salem</span>')
            ->assertSeeHtml('<span class="crm-assignees__more crm-assignees__more--compact-only" aria-hidden="true" dir="ltr"')
            ->assertSeeHtml('<span class="crm-assignees__more-compact">+2</span>')
            ->assertDontSeeHtml('crm-assignees__more-stacked')
            ->assertSeeHtml(e(__('tasks.assignees.everyone', ['names' => $everyone])));

        // The task page: «المكلَّفون» with everyone, the first one first, in both languages.
        foreach (['ar', 'en'] as $locale) {
            $this->admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            Livewire::actingAs($this->admin)->test(ViewTask::class, ['record' => $task->getKey()])
                ->assertSee(__('tasks.fields.assignees'))
                ->assertSeeHtmlInOrder([
                    '<ul class="crm-people" role="list">',
                    '<span class="crm-person__name">Huda Salem</span>',
                    '<span class="crm-person__name">Omar Fahad</span>',
                    '<span class="crm-person__name">Sara Nasser</span>',
                ]);
        }

        app()->setLocale('en');

        // My Work: the handed-out view says who it is for; a holder's own view names the others.
        Livewire::withQueryParams(['view' => MyWorkView::Delegated->value])
            ->actingAs($this->admin)
            ->test(TasksDashboard::class)
            ->assertSee(__('my_work.fields.assigned_to', ['name' => $everyone]))
            ->assertSeeHtml('<span class="crm-assignees__name">Huda Salem</span>');

        Livewire::actingAs($this->omar)
            ->test(TasksDashboard::class)
            ->assertSee(__('tasks.assignees.shared_with', ['names' => 'Huda Salem'.__('tasks.assignees.separator').'Sara Nasser']))
            ->assertSeeHtml('crm-assignees--others');

        // The board: in each holder's column the colleagues beside them; everyone on the by-status card.
        Livewire::withQueryParams(['view' => TasksBoard::VIEW_ASSIGNEE])
            ->actingAs($this->admin)
            ->test(TasksBoard::class)
            ->assertSee(__('tasks.assignees.shared_with', ['names' => 'Omar Fahad'.__('tasks.assignees.separator').'Sara Nasser']))
            ->assertSee(__('tasks.assignees.shared_with', ['names' => 'Huda Salem'.__('tasks.assignees.separator').'Sara Nasser']))
            ->assertSee(__('tasks.assignees.shared_with', ['names' => 'Huda Salem'.__('tasks.assignees.separator').'Omar Fahad']));

        Livewire::withQueryParams(['view' => TasksBoard::VIEW_STATUS])
            ->actingAs($this->admin)
            ->test(TasksBoard::class)
            ->assertSeeHtml('<span class="crm-assignees__name">Huda Salem</span>')
            ->assertSeeHtml('crm-assignees__more--compact-only')
            ->assertDontSeeHtml('crm-assignees__more-stacked');

        // The follow-ups list of the Tasks home, under «المكلَّفون».
        Livewire::actingAs($this->admin)
            ->test(UpcomingFollowUpsWidget::class)
            ->assertSee(__('dashboard.tables.columns.assignees'))
            ->assertSeeHtml('<span class="crm-assignees__name">Huda Salem</span>')
            ->assertSeeHtml('crm-assignees__more--compact-only');
    }

    #[Test]
    public function the_task_page_keeps_naming_every_assignee_on_every_later_request(): void
    {
        $task = $this->handOut('Pack the order', $this->huda, $this->omar);
        $holders = static fn (string ...$names): array => [
            '<ul class="crm-people" role="list">',
            ...array_map(static fn (string $name): string => '<span class="crm-person__name">'.$name.'</span>', $names),
        ];

        // Each later request — the thread filter, the one-click Start — restores the record from its
        // key alone, without its relations: the page loads them again before it renders, so
        // «المكلَّفون» never falls back to «غير مُسند».
        Livewire::actingAs($this->omar)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSeeHtmlInOrder($holders('Huda Salem', 'Omar Fahad'))
            ->call('setThreadFilter', ViewTask::THREAD_COMMENTS)
            ->assertSeeHtmlInOrder($holders('Huda Salem', 'Omar Fahad'))
            ->callAction('statusStart')
            ->assertHasNoActionErrors()
            ->assertSeeHtmlInOrder($holders('Huda Salem', 'Omar Fahad'));

        $this->assertSame(TaskStatus::InProgress, $task->refresh()->status);

        // The page's assign action hands the task to Sara in Huda's place: the page names who holds
        // it now, never the holders it had loaded before.
        Livewire::actingAs($this->admin)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('assign', data: ['owner_id' => $this->sara->getKey()])
            ->assertHasNoActionErrors()
            ->assertSeeHtmlInOrder($holders('Sara Nasser', 'Omar Fahad'))
            ->assertDontSeeHtml('<span class="crm-person__name">Huda Salem</span>');

        $this->assertSame([$this->sara->getKey(), $this->omar->getKey()], $task->refresh()->assigneeIds());
    }

    #[Test]
    public function the_mine_row_names_whoever_handed_the_task_to_the_reader_never_the_reader(): void
    {
        $yasmin = $this->makeUser(CrmRole::Admin, ['name' => 'Yasmin Admin']);
        $handedOutBy = static fn (string $name): string => __('my_work.fields.handed_out_by', ['name' => $name]);

        // An administrator hands a task to Huda and takes a share of it: Huda sees who handed it out,
        // the administrator never sees themselves as the one who handed it to them.
        $this->handOut('Count the stock', $this->huda, $this->admin, ['due_at' => '2026-10-15 17:00:00']);

        // Huda's own to-do, which another administrator then shares with Omar: Yasmin handed it to him.
        $own = $this->tasks->create(['title' => 'Her own to-do', 'due_at' => '2026-10-15 17:00:00'], $this->huda);
        $this->tasks->update($own, [TaskForm::ASSIGNEES => [$this->huda->getKey(), $this->omar->getKey()]], $yasmin);

        Livewire::actingAs($this->admin)->test(TasksDashboard::class)
            ->assertSee('Count the stock')
            ->assertDontSee($handedOutBy('Admin User'));

        Livewire::actingAs($this->huda)->test(TasksDashboard::class)
            ->assertSee('Count the stock')
            ->assertSee($handedOutBy('Admin User'))
            ->assertDontSee($handedOutBy('Huda Salem'))
            ->assertDontSee($handedOutBy('Yasmin Admin'));

        Livewire::actingAs($this->omar)->test(TasksDashboard::class)
            ->assertSee('Her own to-do')
            ->assertSee($handedOutBy('Yasmin Admin'))
            ->assertDontSee($handedOutBy('Huda Salem'));
    }

    #[Test]
    public function below_2xl_the_list_keeps_the_first_avatar_and_the_chip_and_hides_the_rest_visually(): void
    {
        $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));
        $compact = substr($css, (int) strpos($css, '@media (width < 96rem)'));
        $compact = substr($compact, 0, (int) strpos($compact, "\n}\n") + 3);

        $this->assertStringContainsString('.fi-ta-table td.crm-ta-compact .crm-assignees__name', $compact, 'the name is visually hidden, never removed');
        $this->assertStringContainsString('position: absolute;', $compact);
        $this->assertStringContainsString('.fi-ta-table td.crm-ta-compact .crm-assignees__stack .crm-avatar:nth-child(n + 2)', $compact, 'only the first avatar stays');

        // The «+N» chip stays visible, counting everyone but the first beside the lone avatar.
        $this->assertStringNotContainsString('.crm-assignees__more {', $compact);
        $this->assertStringNotContainsString('.crm-assignees__more,', $compact);
        $this->assertStringContainsString(".fi-ta-table td.crm-ta-compact .crm-assignees__more--compact-only {\n        display: inline-block;", $compact);
        $this->assertStringContainsString(".fi-ta-table td.crm-ta-compact .crm-assignees__more-stacked {\n        display: none;", $compact);
        $this->assertStringContainsString(".fi-ta-table td.crm-ta-compact .crm-assignees__more-compact {\n        display: inline;", $compact);

        // Outside it, the chip counts only those beyond the stack.
        $this->assertStringContainsString(".crm-assignees__more-compact,\n.crm-assignees__more--compact-only {\n    display: none;", $css);
    }

    // --- The Sales task tabs ---

    #[Test]
    public function the_sales_task_tab_keeps_its_single_owner_field(): void
    {
        $deal = Deal::factory()->create(['owner_id' => $this->admin->getKey()]);

        Livewire::actingAs($this->admin)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->mountAction(TestAction::make('create')->table())
            ->assertFormFieldExists(OwnerSelect::FIELD, static fn (Select $field): bool => ! $field->isMultiple())
            ->assertFormFieldDoesNotExist(TaskForm::ASSIGNEES)
            ->assertFormFieldDoesNotExist('assignees_display');

        Livewire::actingAs($this->admin)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->callAction(TestAction::make('create')->table(), data: [
                'title' => 'Call the buyer',
                'kind' => TaskKind::Call->value,
                'priority' => TaskPriority::Medium->value,
                OwnerSelect::FIELD => $this->huda->getKey(),
            ])
            ->assertHasNoActionErrors();

        $task = Task::query()->where('title', 'Call the buyer')->sole();
        $this->assertSame([$this->huda->getKey()], $task->assigneeIds());
        $this->assertSame($deal->getKey(), (int) $task->deal_id);
    }

    // --- The export ---

    #[Test]
    public function the_export_lists_the_other_assignees_in_a_column_of_their_own(): void
    {
        Storage::fake('local');
        $sly = $this->makeUser(CrmRole::Employee, ['name' => '=HYPERLINK("http://evil.example")']);
        $shared = $this->handOut('Shared export', $this->huda, $sly, $this->omar);
        $alone = $this->handOut('Alone export', $this->sara);

        Livewire::actingAs($this->admin)
            ->test(ListTasks::class)
            ->set('activeTab', 'all')
            ->callAction(TestAction::make('export')->table())
            ->assertHasNoActionErrors();

        $export = Export::query()->latest('id')->firstOrFail();
        $this->assertSame(2, $export->successful_rows, 'every row exported: the co-assignees ride on the chunk, never one query per row');

        $directory = $export->getFileDirectory();
        $disk = Storage::disk('local');
        $headers = $this->csvRows((string) $disk->get($directory.'/headers.csv'))[0];
        $rows = [];

        foreach ($disk->files($directory) as $file) {
            if (str_ends_with($file, '.csv') && ! str_ends_with($file, 'headers.csv')) {
                foreach ($this->csvRows((string) $disk->get($file)) as $row) {
                    $rows[$row[0]] = array_combine($headers, $row);
                }
            }
        }

        $column = __('exports.columns.task.co_assignees');
        $this->assertContains($column, $headers);

        $sharedRow = $rows[(string) $shared->getKey()];
        $this->assertSame('Huda Salem', $sharedRow[__('exports.columns.task.assignee')]);
        $this->assertSame("'=HYPERLINK(\"http://evil.example\"), Omar Fahad", $sharedRow[$column], 'the others, in the order added, protected against formula injection');
        $this->assertSame('', $rows[(string) $alone->getKey()][$column]);
    }

    /**
     * A task the administrator handed out to the given employees through
     * TaskService, the first one first; an array last gives more attributes.
     *
     * @param  User|array<string, mixed>  ...$assignees
     */
    private function handOut(string $title, User|array ...$assignees): Task
    {
        $attributes = [];
        $ids = [];

        foreach ($assignees as $assignee) {
            if (is_array($assignee)) {
                $attributes = $assignee;

                continue;
            }

            $ids[] = (int) $assignee->getKey();
        }

        return $this->tasks->create([...$attributes, 'title' => $title, TaskForm::ASSIGNEES => $ids], $this->admin);
    }

    /** A bare page holding TaskActions' quick controls (quick add among them), as the given reader. */
    private function host(User $user): Testable
    {
        $this->actingAs($user);

        return Livewire::actingAs($user)->test(TaskActionsHost::class, ['taskId' => 0]);
    }

    /**
     * @return list<list<string>>
     */
    private function csvRows(string $csv): array
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, escape: '')) !== false) {
            if ($row !== [null]) {
                $rows[] = array_map(static fn (?string $cell): string => (string) $cell, $row);
            }
        }

        fclose($stream);

        return $rows;
    }
}
