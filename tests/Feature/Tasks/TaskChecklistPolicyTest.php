<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\CrmRole;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\DealTasksRelationManager;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Models\Deal;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\Team;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Decision D-25, the authorisation split: writing a task's checklist is
 * `update` (the assigner, administrators, the team manager within reach,
 * the creator of their own task — never the handed-out assignee), ticking it
 * is `tickChecklist` (`progress` on an open task — the handed-out assignee
 * included, a mentioned participant never). A closed or deleted task's
 * checklist takes no ticks. Every refusal is a policy answer, checked again
 * on a forged request, not a hidden button.
 */
final class TaskChecklistPolicyTest extends TestCase
{
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
        $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));
        config()->set('mail.default', 'log');

        $this->team = $this->makeTeam();
        $this->admin = $this->admin();
        $this->manager = $this->salesManager($this->team);
        $this->rep = $this->salesRep($this->team);
        $this->tasks = app(TaskService::class);
    }

    #[Test]
    public function the_per_role_matrix_splits_writing_from_ticking(): void
    {
        $task = $this->handedTo($this->rep);
        $employee = $this->employee($this->team);
        $employeeTask = $this->handedTo($employee);

        $matrix = [
            'super admin' => [$this->superAdmin(), $task, true, true],
            'admin (the assigner)' => [$this->admin, $task, true, true],
            'team manager' => [$this->manager, $task, true, true],
            'handed-out rep' => [$this->rep, $task, false, true],
            'handed-out employee' => [$employee, $employeeTask, false, true],
            'rep of another team' => [$this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة')), $task, false, false],
            'read only' => [$this->readOnly($this->team), $task, false, false],
            'support (updates tasks at all level)' => [$this->support($this->team), $task, true, true],
        ];

        foreach ($matrix as $who => [$user, $subject, $writes, $ticks]) {
            $this->assertSame($writes, $user->can('update', $subject), "{$who}: writing the checklist");
            $this->assertSame($ticks, $user->can('tickChecklist', $subject), "{$who}: ticking the checklist");
        }

        $own = $this->tasks->create(['title' => 'My own list', 'assignee_id' => $this->rep->getKey(), 'checklist' => [['body' => 'Step']]], $this->rep);
        $this->assertTrue($this->rep->can('update', $own), 'a task the rep created for themselves stays fully theirs');
        $this->assertTrue($this->rep->can('tickChecklist', $own));
    }

    #[Test]
    public function a_mentioned_participant_may_read_the_checklist_but_never_tick_it(): void
    {
        $employee = $this->employee($this->team);
        $colleague = $this->makeUser(CrmRole::Employee, ['name' => 'Omar Employee'], $this->makeTeam('Jeddah Team', 'فريق جدة'));
        $task = $this->handedTo($employee);
        $this->tasks->comment($task, $employee, '@'.$colleague->name.' have a look', [(int) $colleague->getKey()]);

        $this->assertTrue($colleague->can('view', $task), 'precondition: the mention opens the task');
        $this->assertFalse($colleague->can('tickChecklist', $task));
        $this->assertFalse($colleague->can('update', $task));

        Livewire::actingAs($colleague)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSee('Compare')
            ->assertActionHidden('checklist')
            // The request a browser would send for the hidden button.
            ->call('mountAction', 'checklist')
            ->assertActionNotMounted('checklist')
            ->call('callMountedAction');

        $this->assertNull($this->firstItem($task)->completed_at, 'the forged tick was refused');
    }

    #[Test]
    public function a_closed_or_deleted_task_takes_no_ticks_from_anyone(): void
    {
        $completed = $this->handedTo($this->rep);
        $this->tasks->complete($completed, $this->rep);

        $cancelled = $this->handedTo($this->rep);
        $this->tasks->cancel($cancelled, $this->admin);

        $deleted = $this->handedTo($this->rep);
        $deleted->delete();

        foreach (['completed' => $completed->refresh(), 'cancelled' => $cancelled->refresh(), 'deleted' => $deleted->refresh()] as $state => $task) {
            foreach ([$this->admin, $this->manager, $this->rep] as $user) {
                $this->assertFalse($user->can('tickChecklist', $task), "{$user->name} ticks a {$state} task");
            }
        }

        Livewire::actingAs($this->admin)
            ->test(ViewTask::class, ['record' => $completed->getKey()])
            ->assertActionHidden('checklist');
    }

    #[Test]
    public function the_view_page_offers_the_handed_out_assignee_the_checklist_and_saves_their_ticks(): void
    {
        $task = $this->handedTo($this->rep);
        [$first, $second] = TaskChecklistItem::query()->where('task_id', $task->getKey())->orderBy('position')->get()->all();

        Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertActionVisible('checklist')
            ->assertActionHidden('edit')
            ->mountAction('checklist')
            ->assertActionDataSet(['done' => [], 'shown_done' => []])
            ->setActionData(['done' => [(string) $first->getKey(), (string) $second->getKey()]])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified(__('tasks.notifications.checklist_saved'))
            ->assertSee(__('tasks.fields.checklist_progress', ['done' => 2, 'total' => 3]));

        $this->assertSame($this->rep->getKey(), $first->refresh()->completed_by);
        $this->assertSame($this->rep->getKey(), $second->refresh()->completed_by);
    }

    #[Test]
    public function the_dialog_writes_only_what_changed_since_it_opened(): void
    {
        $task = $this->handedTo($this->rep);
        [$first, $second, $third] = TaskChecklistItem::query()->where('task_id', $task->getKey())->orderBy('position')->get()->all();

        $component = Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->mountAction('checklist');

        // Meanwhile the assigner ticks the second item.
        $this->tasks->tickChecklist($task, $this->admin, [$second->getKey()]);

        // The rep's dialog never showed that tick; ticking the first must not undo it.
        $component
            ->setActionData(['done' => [(string) $first->getKey()]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($this->rep->getKey(), $first->refresh()->completed_by);
        $this->assertSame($this->admin->getKey(), $second->refresh()->completed_by, 'the concurrent tick survives');
        $this->assertNull($third->refresh()->completed_at);
    }

    #[Test]
    public function the_list_row_menu_and_the_relation_manager_follow_the_same_answers(): void
    {
        $task = $this->handedTo($this->rep);

        Livewire::actingAs($this->rep)
            ->test(ListTasks::class)
            ->assertTableActionVisible('checklist', $task)
            ->assertTableActionHidden('edit', $task);

        $deal = Deal::factory()->create(['owner_id' => $this->rep->getKey()]);
        $onDeal = $this->tasks->create([
            'title' => 'Call the buyer',
            'assignee_id' => $this->rep->getKey(),
            'deal_id' => $deal->getKey(),
            'checklist' => [['body' => 'Dial'], ['body' => 'Log the call']],
        ], $this->admin);
        $dial = $this->firstItem($onDeal);

        Livewire::actingAs($this->rep)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->assertTableActionVisible('checklist', $onDeal)
            ->assertTableActionHidden('edit', $onDeal)
            ->callTableAction('checklist', $onDeal, data: ['done' => [(string) $dial->getKey()]])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('tasks.notifications.checklist_saved'));

        $this->assertNotNull($dial->refresh()->completed_at);

        // A task without a checklist offers no checklist action.
        $plain = $this->tasks->create(['title' => 'Plain', 'assignee_id' => $this->rep->getKey()], $this->admin);

        Livewire::actingAs($this->rep)
            ->test(ListTasks::class)
            ->assertTableActionHidden('checklist', $plain);
    }

    private function handedTo(User $assignee): Task
    {
        return $this->tasks->create([
            'title' => 'Check the stock',
            'assignee_id' => $assignee->getKey(),
            'checklist' => [['body' => 'Count'], ['body' => 'Compare'], ['body' => 'Report']],
        ], $this->admin);
    }

    private function firstItem(Task $task): TaskChecklistItem
    {
        return TaskChecklistItem::query()->where('task_id', $task->getKey())->orderBy('position')->orderBy('id')->firstOrFail();
    }
}
