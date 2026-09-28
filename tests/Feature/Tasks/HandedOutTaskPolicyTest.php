<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Filament\Pages\Calendar;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\DealTasksRelationManager;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Deal;
use App\Models\Task;
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
 * Decision D-14, amended by the owner on 2026-09-28: a handed-out task
 * belongs to its assigner. For its assignee — `assigned_by` names someone
 * else and the assignee does not hold `task.assign` — the details, the
 * cancel, the delete, the restore and the calendar move are refused, while
 * start, post update, complete and reopen-from-completed stay theirs
 * (`progress`, the former `update` rule). The assigner, administrators and a
 * team manager within reach still edit; a task the user created for
 * themselves and a task from before `assigned_by` existed are unaffected.
 * Every refusal is a policy answer, not a hidden button.
 */
final class HandedOutTaskPolicyTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    private User $manager;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-09-28 09:00:00'));
        config()->set('mail.default', 'log');

        $this->team = $this->makeTeam();
        $this->admin = $this->admin();
        $this->manager = $this->salesManager($this->team);
        $this->rep = $this->salesRep($this->team);
    }

    #[Test]
    public function the_handed_out_assignee_reports_progress_but_may_not_edit_cancel_delete_or_restore(): void
    {
        $task = $this->handedOut();

        $this->assertTrue($task->isHandedOutTo($this->rep), 'precondition: the admin handed the task to the rep');
        $this->assertFalse($this->rep->can(Permission::TaskAssign->value), 'precondition: reps may not hand tasks out');

        $this->assertTrue($this->rep->can('view', $task));
        $this->assertTrue($this->rep->can('progress', $task));
        $this->assertTrue($this->rep->can('start', $task));
        $this->assertTrue($this->rep->can('postUpdate', $task));
        $this->assertTrue($this->rep->can('complete', $task));

        $this->assertFalse($this->rep->can('update', $task), 'the details stay with the assigner');
        $this->assertFalse($this->rep->can('cancel', $task), 'cancelling is the assigner\'s decision');
        $this->assertFalse($this->rep->can('delete', $task));
        $this->assertFalse($this->rep->can('assign', $task));

        app(TaskService::class)->complete($task, $this->rep, 'Done');
        $this->assertTrue($this->rep->can('reopen', $task->refresh()), 'a task the assignee completed reopens for them');

        app(TaskService::class)->reopen($task, $this->admin);
        app(TaskService::class)->cancel($task->refresh(), $this->admin);
        $this->assertFalse($this->rep->can('reopen', $task->refresh()), 'a cancelled task reopens only for those who may edit it');
        $this->assertTrue($this->admin->can('reopen', $task));

        $task->delete();
        $this->assertFalse($this->rep->can('restore', $task->refresh()));
        $this->assertTrue($this->admin->can('restore', $task));
        $this->assertFalse($this->rep->can('progress', $task), 'a deleted task is frozen for the assignee too (D-13)');
    }

    #[Test]
    public function the_assigner_the_administrators_and_the_team_manager_keep_editing(): void
    {
        $task = $this->handedOut();
        $otherAdmin = $this->superAdmin();

        foreach ([$this->admin, $otherAdmin, $this->manager] as $editor) {
            $this->assertTrue($editor->can('update', $task), "{$editor->name} edits the details");
            $this->assertTrue($editor->can('cancel', $task), "{$editor->name} cancels");
            $this->assertTrue($editor->can('delete', $task), "{$editor->name} deletes");
            $this->assertTrue($editor->can('progress', $task), "{$editor->name} may report too");
        }

        $this->assertFalse($this->readOnly()->can('progress', $task), 'progress still needs task.update');
        $this->assertFalse($this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'))->can('progress', $task), 'progress still needs reach');
    }

    #[Test]
    public function a_task_the_user_created_for_themselves_stays_fully_theirs(): void
    {
        $own = app(TaskService::class)->create(['title' => 'My own follow-up', 'assignee_id' => $this->rep->getKey()], $this->rep);

        $this->assertSame($this->rep->getKey(), (int) $own->assigned_by, 'precondition: the rep is their own assigner');
        $this->assertFalse($own->isHandedOutTo($this->rep));

        foreach (['update', 'cancel', 'delete', 'progress', 'start', 'postUpdate', 'complete'] as $ability) {
            $this->assertTrue($this->rep->can($ability, $own), "own task: {$ability}");
        }
    }

    #[Test]
    public function a_task_from_before_assigned_by_existed_is_not_handed_out(): void
    {
        $legacy = Task::factory()->create([
            'assignee_id' => $this->rep->getKey(),
            'assigned_by' => null,
            'created_by' => $this->admin->getKey(),
        ]);

        $this->assertFalse($legacy->isHandedOutTo($this->rep));

        foreach (['update', 'cancel', 'delete', 'progress'] as $ability) {
            $this->assertTrue($this->rep->can($ability, $legacy), "legacy task: {$ability}");
        }
    }

    #[Test]
    public function a_manager_handed_a_task_by_an_administrator_is_limited_like_any_assignee(): void
    {
        // Under admin-only assignment a manager does not hold task.assign, so
        // the rule applies to them exactly as to a rep.
        $task = app(TaskService::class)->create(['title' => 'Quarterly review', 'assignee_id' => $this->manager->getKey()], $this->admin);

        $this->assertFalse($this->manager->can('update', $task));
        $this->assertFalse($this->manager->can('cancel', $task));
        $this->assertTrue($this->manager->can('complete', $task));
    }

    #[Test]
    public function a_holder_of_task_assign_is_never_limited_by_the_rule(): void
    {
        $task = app(TaskService::class)->create(['title' => 'Budget sign-off', 'assignee_id' => $this->admin->getKey()], $this->superAdmin());

        $this->assertTrue($task->isHandedOutTo($this->admin), 'precondition: handed out to the admin by someone else');
        $this->assertTrue($this->admin->can('update', $task));
        $this->assertTrue($this->admin->can('cancel', $task));
        $this->assertTrue($this->admin->can('delete', $task));
    }

    #[Test]
    public function the_bulk_abilities_keep_their_semantics(): void
    {
        $this->assertTrue($this->rep->can('deleteAny', Task::class));
        $this->assertTrue($this->rep->can('restoreAny', Task::class));
        $this->assertFalse($this->admin->can('forceDelete', $this->handedOut()));
        $this->assertFalse($this->admin->can('forceDeleteAny', Task::class));
    }

    #[Test]
    public function the_edit_page_is_forbidden_to_the_handed_out_assignee_and_open_to_the_assigner(): void
    {
        $task = $this->handedOut();

        $this->actingAs($this->rep)->get(TaskResource::getUrl('view', ['record' => $task]))->assertOk();
        $this->actingAs($this->rep)->get(TaskResource::getUrl('edit', ['record' => $task]))->assertForbidden();
        $this->actingAs($this->admin)->get(TaskResource::getUrl('edit', ['record' => $task]))->assertOk();

        Livewire::actingAs($this->rep)
            ->test(EditTask::class, ['record' => $task->getKey()])
            ->assertForbidden();
    }

    #[Test]
    public function the_view_page_offers_the_assignee_start_update_and_complete_but_not_edit_cancel_or_delete(): void
    {
        $task = $this->handedOut();

        Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertActionVisible('start')
            ->assertActionVisible('postUpdate')
            ->assertActionVisible('complete')
            ->assertActionHidden('edit')
            ->assertActionHidden('cancel')
            ->assertActionHidden('delete')
            ->assertActionHidden('assign')
            ->assertSee(__('tasks.helpers.handed_out'));

        Livewire::actingAs($this->admin)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertActionVisible('edit')
            ->assertActionVisible('cancel')
            ->assertActionVisible('delete')
            ->assertDontSee(__('tasks.helpers.handed_out'));
    }

    #[Test]
    public function the_task_list_row_menu_follows_the_same_answers(): void
    {
        $task = $this->handedOut();

        // One row per assertion pass: Filament's per-action visibility cache is
        // keyed by the record object's id, which a test helper may reuse
        // across two freshly resolved rows.
        Livewire::actingAs($this->rep)
            ->test(ListTasks::class)
            ->assertTableActionVisible('start', $task)
            ->assertTableActionVisible('postUpdate', $task)
            ->assertTableActionVisible('complete', $task)
            ->assertTableActionHidden('edit', $task)
            ->assertTableActionHidden('cancel', $task);

        $own = app(TaskService::class)->create(['title' => 'My own follow-up', 'assignee_id' => $this->rep->getKey()], $this->rep);

        // The rendered list carries the edit link of the rep's own task only.
        Livewire::actingAs($this->rep)
            ->test(ListTasks::class)
            ->assertSeeHtml(TaskResource::getUrl('edit', ['record' => $own]))
            ->assertDontSeeHtml(TaskResource::getUrl('edit', ['record' => $task]));
    }

    #[Test]
    public function the_relation_manager_rows_follow_the_same_answers(): void
    {
        $deal = Deal::factory()->create(['owner_id' => $this->rep->getKey()]);
        $task = app(TaskService::class)->create(['title' => 'Call the buyer', 'assignee_id' => $this->rep->getKey(), 'deal_id' => $deal->getKey()], $this->admin);

        Livewire::actingAs($this->rep)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->assertTableActionVisible('start', $task)
            ->assertTableActionVisible('postUpdate', $task)
            ->assertTableActionVisible('complete', $task)
            ->assertTableActionHidden('edit', $task)
            ->assertTableActionHidden('cancel', $task)
            ->assertTableActionHidden('delete', $task)
            ->callTableAction('start', $task, data: ['start_note' => 'On it'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('tasks.notifications.started'));

        $this->assertSame(TaskStatus::InProgress, $task->refresh()->status);
    }

    #[Test]
    public function a_forged_cancel_is_refused_by_the_policy_not_by_the_hidden_button(): void
    {
        $task = $this->handedOut();

        // The request a browser would send for the hidden button.
        Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->call('mountAction', 'cancel')
            ->assertActionNotMounted('cancel')
            ->call('callMountedAction');

        $this->assertSame(TaskStatus::Pending, $task->refresh()->status, 'the cancel must not run for the handed-out assignee');

        Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->call('mountAction', 'edit')
            ->assertActionNotMounted('edit');
    }

    #[Test]
    public function the_calendar_refuses_the_assignee_a_move_and_sends_their_click_to_the_task_page(): void
    {
        $task = $this->handedOut(['due_at' => '2026-09-30 10:00:00']);

        Livewire::actingAs($this->rep)
            ->test(Calendar::class)
            ->call('moveTask', $task->getKey(), '2026-10-01T09:30:00+03:00', null, false)
            ->assertForbidden();

        $this->assertSame('2026-09-30 10:00:00', $task->refresh()->due_at?->format('Y-m-d H:i:s'));

        $mine = $this->calendarEntry($this->rep, $task);
        $this->assertFalse($mine['editable'], 'the assignee cannot drag it');
        $this->assertFalse($mine['extendedProps']['canEdit'], 'a click opens the task page, not the edit modal');
        $this->assertSame(TaskResource::getUrl('view', ['record' => $task]), $mine['url']);

        Livewire::actingAs($this->rep)
            ->test(Calendar::class)
            ->mountAction('editTask', ['task' => $task->getKey()])
            ->assertActionNotMounted('editTask');

        $assigners = $this->calendarEntry($this->admin, $task);
        $this->assertTrue($assigners['editable']);
        $this->assertTrue($assigners['extendedProps']['canEdit']);

        Livewire::actingAs($this->admin)
            ->test(Calendar::class)
            ->call('moveTask', $task->getKey(), '2026-10-01T09:30:00+03:00', null, false)
            ->assertOk();

        $this->assertSame('2026-10-01', Task::query()->findOrFail($task->getKey())->due_at?->format('Y-m-d'), 'the assigner moves it');
    }

    /**
     * A task the administrator handed to the rep.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function handedOut(array $attributes = []): Task
    {
        return app(TaskService::class)->create(['title' => 'Visit the client', 'assignee_id' => $this->rep->getKey(), ...$attributes], $this->admin);
    }

    /**
     * The viewer's calendar entry for the task, as the browser receives it.
     *
     * @return array<string, mixed>
     */
    private function calendarEntry(User $viewer, Task $task): array
    {
        $page = Livewire::actingAs($viewer)->test(Calendar::class)->instance();
        $this->assertInstanceOf(Calendar::class, $page);

        foreach ($page->events('2026-09-01', '2026-11-01') as $event) {
            if ($event['id'] === 'task-'.$task->getKey()) {
                return $event;
            }
        }

        $this->fail('the task is missing from the viewer\'s calendar');
    }
}
