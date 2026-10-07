<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Tables\TasksTable;
use App\Filament\Support\TaskActions;
use App\Models\Task;
use App\Models\User;
use App\Services\Settings\OrganisationCalendar;
use App\Services\Tasks\TaskService;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Grouping\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Js;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The Tasks-workspace task list (decision D-27): grouped by status — In
 * progress first, the closed groups collapsed — each row with a one-click
 * completion check, a status pill that changes status in place and a due
 * date that reschedules, quick add as the primary header action, «مهامي
 * فقط», friendly empty states and tabs on the organisation calendar.
 *
 * Every row control is held against the rules it must keep, for four
 * readers: the administrator who handed the task out, the employee it was
 * handed to (D-14 amendment: reports progress, never edits dates or
 * cancels), a colleague mentioned on it (D-20: reads and comments only)
 * and an outsider who may not open it — offered controls in the rendered
 * row, refusals on the server for forged mounts, and D-25's warning before
 * completing with open checklist items.
 */
final class TaskListWorkspaceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private User $participant;

    private User $outsider;

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

        $this->admin = $this->admin();
        $this->employee = $this->employee();
        $this->participant = $this->employee();
        $this->outsider = $this->employee();
        $this->tasks = app(TaskService::class);

        $this->task = $this->tasks->create([
            'title' => 'Prepare the visit',
            'assignee_id' => $this->employee->getKey(),
            'due_at' => '2026-10-10 09:00:00',
        ], $this->admin);

        $this->tasks->comment($this->task, $this->admin, '@'.$this->participant->name.' please help', [(int) $this->participant->getKey()]);
    }

    #[Test]
    public function the_list_is_grouped_by_status_with_the_work_in_hand_first_and_the_closed_groups_collapsed(): void
    {
        $owner = $this->admin;
        $pending = $this->taskAssignedTo($owner, TaskStatus::Pending, ['title' => 'Pending one', 'due_at' => '2026-10-08 09:00:00']);
        $started = $this->taskAssignedTo($owner, TaskStatus::InProgress, ['title' => 'Started one', 'due_at' => '2026-10-09 09:00:00']);
        $completed = $this->taskAssignedTo($owner, TaskStatus::Completed, ['title' => 'Completed one', 'due_at' => '2026-10-01 09:00:00', 'completed_at' => '2026-10-02 09:00:00']);
        $cancelled = $this->taskAssignedTo($owner, TaskStatus::Cancelled, ['title' => 'Cancelled one', 'due_at' => '2026-10-01 08:00:00']);

        $list = Livewire::actingAs($owner)->test(ListTasks::class)->set('activeTab', 'all');

        $page = $list->instance();
        $this->assertInstanceOf(ListTasks::class, $page);

        $table = $page->getTable();
        $this->assertInstanceOf(Group::class, $table->getGrouping(), 'the list is grouped by default');
        $this->assertSame('status', $table->getGrouping()->getId());
        $this->assertTrue($table->getGrouping()->isCollapsible());

        $order = array_map(
            static fn (Task $task): string => $task->status->value,
            array_values($page->getTableRecords()->all()),
        );
        $this->assertSame(
            ['in_progress', 'pending', 'pending', 'completed', 'cancelled'],
            $order,
            'In progress first, then Pending (by due date), then the closed groups',
        );

        foreach (['en', 'ar'] as $locale) {
            $owner->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $html = Livewire::actingAs($owner)->test(ListTasks::class)->set('activeTab', 'all')->html();

            preg_match_all('/class="fi-ta-group-heading"\s*>\s*(.*?)\s*</su', (string) preg_replace('/<!--.*?-->/s', '', $html), $headings);
            $this->assertSame(
                array_map(static fn (TaskStatus $status): string => e($status->getLabel()), TasksTable::STATUS_ORDER),
                $headings[1],
                "{$locale}: the group headers read In progress, Pending, Completed, Cancelled",
            );

            // The closed groups start collapsed: their titles seed the table's collapsed set once.
            $this->assertStringContainsString(
                'groupVisibility.push(...'.Js::from([TaskStatus::Completed->getLabel(), TaskStatus::Cancelled->getLabel()])->toHtml().')',
                html_entity_decode($html, ENT_QUOTES | ENT_HTML5),
                "{$locale}: the Completed and Cancelled groups do not start collapsed",
            );
        }

        $list->assertCanSeeTableRecords([$pending, $started, $completed, $cancelled]);
    }

    #[Test]
    public function each_reader_is_offered_exactly_the_row_controls_their_policy_allows(): void
    {
        $controls = ['check' => $this->cellClick('quickComplete', $this->task), 'pill' => $this->cellClick('changeStatus', $this->task), 'due' => $this->cellClick('reschedule', $this->task)];

        $expected = [
            'admin (assigner)' => [$this->admin, ['check', 'pill', 'due']],
            'handed-out assignee' => [$this->employee, ['check', 'pill']],
            'mentioned participant' => [$this->participant, []],
        ];

        foreach ($expected as $reader => [$user, $offered]) {
            $html = $this->list($user)->assertCanSeeTableRecords([$this->task])->html();

            foreach ($controls as $control => $click) {
                in_array($control, $offered, true)
                    ? $this->assertStringContainsString($click, $html, "{$reader}: the {$control} control is missing")
                    : $this->assertStringNotContainsString($click, $html, "{$reader}: the {$control} control is offered");
            }

            // The pill says what it does for a screen reader only where it is a control.
            $name = '<span class="sr-only">'.e(__('tasks.board_ux.actions.change_status', ['status' => $this->task->status->getLabel(), 'task' => $this->task->title])).'</span>';
            in_array('pill', $offered, true)
                ? $this->assertStringContainsString($name, $html, "{$reader}: the pill's name")
                : $this->assertStringNotContainsString($name, $html, "{$reader}: a pill that is no control is named as one");
        }

        $this->list($this->outsider)->assertCanNotSeeTableRecords([$this->task]);
    }

    #[Test]
    public function the_completion_check_completes_in_one_click_through_the_service(): void
    {
        $this->list($this->employee)
            ->callAction(TestAction::make('quickComplete')->table($this->task))
            ->assertNotified(__('tasks.notifications.completed'));

        $this->assertSame(TaskStatus::Completed, $this->task->refresh()->status);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskCompleted->value, 'subject_id' => $this->task->getKey(), 'causer_id' => $this->employee->getKey()]);

        // Completed: the check is a filled mark, no longer a button.
        $html = $this->list($this->employee)->html();
        $this->assertStringNotContainsString($this->cellClick('quickComplete', $this->task), $html);
        $this->assertStringContainsString('crm-task-check--done', $html);
    }

    #[Test]
    public function the_completion_check_shows_the_checklist_warning_first_when_items_are_open(): void
    {
        $this->checklistItemOn($this->task, 'Book the car');
        $this->checklistItemOn($this->task, 'Print the brochure', now());

        $warning = trans_choice('tasks.confirmations.checklist_open', 2, ['count' => 1, 'total' => 2]);

        $list = $this->list($this->employee)
            ->mountAction(TestAction::make('quickComplete')->table($this->task))
            ->assertActionMounted(TestAction::make('quickComplete')->table($this->task))
            ->assertMountedActionModalSee($warning);

        $this->assertSame(TaskStatus::Pending, $this->task->refresh()->status, 'nothing is completed before the warning is confirmed');

        $list->callMountedAction()->assertHasNoActionErrors();

        $this->assertSame(TaskStatus::Completed, $this->task->refresh()->status);
    }

    #[Test]
    public function the_status_pill_moves_the_task_through_the_allowed_transition_only(): void
    {
        $this->list($this->employee)
            ->assertActionVisible([TestAction::make('changeStatus')->table($this->task), TestAction::make('statusStart')])
            ->assertActionHidden([TestAction::make('changeStatus')->table($this->task), TestAction::make('statusCancel')])
            ->callAction([TestAction::make('changeStatus')->table($this->task), TestAction::make('statusStart')]);

        $this->assertSame(TaskStatus::InProgress, $this->task->refresh()->status);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskStarted->value, 'subject_id' => $this->task->getKey(), 'causer_id' => $this->employee->getKey()]);

        $this->list($this->admin)
            ->assertActionVisible([TestAction::make('changeStatus')->table($this->task), TestAction::make('statusCancel')]);
    }

    #[Test]
    public function forged_row_mounts_change_nothing(): void
    {
        $forged = static fn (Testable $list, string $action, Task $task): Testable => $list->call('mountAction', $action, [], ['table' => true, 'recordKey' => (string) $task->getKey()]);

        // The mentioned participant may open the task but neither complete nor change its status.
        $forged($this->list($this->participant), 'quickComplete', $this->task);
        $forged($this->list($this->participant), 'changeStatus', $this->task);
        // The handed-out assignee may not move the dates (D-14 amendment).
        $forged($this->list($this->employee), 'reschedule', $this->task)->assertActionNotMounted(TestAction::make('reschedule')->table($this->task));
        // The outsider cannot even resolve the row.
        $forged($this->list($this->outsider), 'quickComplete', $this->task);

        $this->task->refresh();
        $this->assertSame(TaskStatus::Pending, $this->task->status);
        $this->assertSame('2026-10-10 09:00:00', $this->task->due_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_assigner_reschedules_from_the_due_date(): void
    {
        $this->list($this->admin)
            ->callAction(TestAction::make('reschedule')->table($this->task), data: ['due_shortcut' => 'tomorrow'])
            ->assertHasNoActionErrors();

        // Tomorrow on the organisation calendar, at the task's own time of day.
        $this->assertSame('2026-10-08 09:00', app(OrganisationCalendar::class)->local($this->task->refresh()->due_at ?? now())->format('Y-m-d H:i'));
    }

    #[Test]
    public function quick_add_is_the_primary_header_action_and_opens_from_the_address(): void
    {
        $list = Livewire::actingAs($this->employee)->test(ListTasks::class)
            ->assertActionVisible('quickAdd')
            ->assertActionVisible('create');

        $page = $list->instance();
        $this->assertInstanceOf(ListTasks::class, $page);
        $this->assertSame('quickAdd', array_values($page->getCachedHeaderActions())[0]->getName(), 'quick add leads the header');

        Livewire::withQueryParams(['action' => 'quickAdd'])
            ->actingAs($this->employee)
            ->test(ListTasks::class)
            ->assertSet('defaultAction', 'quickAdd')
            ->assertSeeHtml("wire:init=\"mountAction('quickAdd'");
    }

    #[Test]
    public function the_tabs_read_the_organisation_day(): void
    {
        $owner = $this->employee;
        $earlierToday = $this->taskAssignedTo($owner, TaskStatus::Pending, ['title' => 'Earlier today', 'due_at' => '2026-10-07 08:00:00']);
        $yesterday = $this->taskAssignedTo($owner, TaskStatus::Pending, ['title' => 'Late yesterday', 'due_at' => '2026-10-06 23:00:00']);
        $tomorrow = $this->taskAssignedTo($owner, TaskStatus::Pending, ['title' => 'Tomorrow first thing', 'due_at' => '2026-10-08 00:00:00']);

        $list = Livewire::actingAs($owner)->test(ListTasks::class);

        $list->set('activeTab', 'today')
            ->assertCanSeeTableRecords([$earlierToday])
            ->assertCanNotSeeTableRecords([$yesterday, $tomorrow]);

        $list->set('activeTab', 'overdue')
            ->assertCanSeeTableRecords([$yesterday])
            ->assertCanNotSeeTableRecords([$earlierToday, $tomorrow]);

        $list->set('activeTab', 'upcoming')
            ->assertCanSeeTableRecords([$tomorrow, $this->task])
            ->assertCanNotSeeTableRecords([$earlierToday, $yesterday]);
    }

    #[Test]
    public function my_tasks_only_narrows_every_tab(): void
    {
        $mine = $this->taskAssignedTo($this->admin, TaskStatus::Pending, ['title' => 'Admin own task']);

        Livewire::actingAs($this->admin)->test(ListTasks::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$mine, $this->task])
            ->filterTable('mine', true)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$this->task]);
    }

    #[Test]
    public function the_empty_states_are_friendly_and_offer_quick_add_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            // The panel speaks the reader's own language (D-5).
            $newcomer = $this->makeUser(CrmRole::Employee, ['locale' => $locale]);
            app()->setLocale($locale);

            foreach (['my', 'overdue', 'completed'] as $tab) {
                Livewire::actingAs($newcomer)->test(ListTasks::class)
                    ->set('activeTab', $tab)
                    ->assertSee(__('tasks.list_ux.empty.'.$tab.'.heading'))
                    ->assertSee(__('tasks.list_ux.empty.'.$tab.'.description'))
                    ->assertSeeHtml("mountAction('quickAdd', {}, JSON.parse('{\u0022table\u0022:true}'))");
            }
        }

        // A reader who may not add tasks gets no quick add, so the words never send them to it.
        foreach (['en', 'ar'] as $locale) {
            $viewer = $this->makeUser(CrmRole::ReadOnly, ['locale' => $locale]);
            app()->setLocale($locale);
            $this->assertFalse($viewer->can('create', Task::class), 'precondition: read-only adds nothing');

            foreach (['my', 'overdue', 'completed'] as $tab) {
                Livewire::actingAs($viewer)->test(ListTasks::class)
                    ->set('activeTab', $tab)
                    ->assertSee(__('tasks.list_ux.empty.'.$tab.'.heading'))
                    ->assertSee(__('tasks.list_ux.empty.'.$tab.'.description_view'))
                    ->assertDontSee(__('tasks.list_ux.empty.'.$tab.'.description'))
                    ->assertDontSeeHtml("mountAction('quickAdd'");
            }
        }

        // Last, as the search persists in the session: a search that finds nothing says so.
        foreach (['en', 'ar'] as $locale) {
            $newcomer = $this->makeUser(CrmRole::Employee, ['locale' => $locale]);
            app()->setLocale($locale);

            Livewire::actingAs($newcomer)->test(ListTasks::class)
                ->set('activeTab', 'all')
                ->searchTable('nothing like this')
                ->assertSee(__('tasks.list_ux.empty.filtered.heading'));
        }
    }

    #[Test]
    public function an_undo_elsewhere_redraws_the_rows(): void
    {
        $this->tasks->complete($this->task, $this->employee);

        $list = $this->list($this->employee)->assertSeeHtml('crm-task-check--done');

        $this->tasks->undoCompletion($this->task, $this->employee);

        $list->dispatch(TaskActions::CHANGED_EVENT)
            ->assertSeeHtml($this->cellClick('quickComplete', $this->task));
    }

    /** The click a table cell carries when its action is offered, as Filament renders it (attribute-escaped). */
    private function cellClick(string $action, Task $task): string
    {
        return e("mountTableAction('{$action}', '{$task->getKey()}')");
    }

    private function list(User $user): Testable
    {
        $this->actingAs($user);

        return Livewire::actingAs($user)->test(ListTasks::class)->set('activeTab', 'all');
    }
}
