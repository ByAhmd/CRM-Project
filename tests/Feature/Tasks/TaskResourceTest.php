<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\RecurrenceFrequency;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Notifications\RecordAssignedNotification;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The task resource (decisions D-4, A-10): the form, its validation and the
 * read-only status, the list tabs, the two reading paths (own scope OR a
 * readable subject), the status actions, assignment and the navigation
 * badge — every refusal a policy answer, not a hidden button.
 */
final class TaskResourceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-09-06 12:00:00'));
    }

    #[Test]
    public function a_rep_creates_a_recurring_follow_up_on_a_deal_from_the_form(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(CreateTask::class)
            ->fillForm([
                'title' => 'متابعة العرض',
                'kind' => TaskKind::FollowUp->value,
                'priority' => TaskPriority::High->value,
                'description' => 'Ask about the budget.',
                'due_at' => '2026-09-10 10:00:00',
                'reminder_at' => '2026-09-10 09:00:00',
                'deal_id' => $deal->getKey(),
                'recurrence_frequency' => RecurrenceFrequency::Weekly->value,
                'recurrence_interval' => 2,
                'recurrence_ends_at' => '2026-12-31',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $task = Task::query()->where('title', 'متابعة العرض')->firstOrFail();

        $this->assertSame(TaskKind::FollowUp, $task->kind);
        $this->assertSame(TaskPriority::High, $task->priority);
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertSame('2026-09-10 10:00:00', $task->due_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-10 09:00:00', $task->reminder_at?->format('Y-m-d H:i:s'));
        $this->assertSame($deal->getKey(), (int) $task->deal_id);
        $this->assertSame($rep->getKey(), (int) $task->assignee_id);
        $this->assertSame($rep->getKey(), (int) $task->created_by);
        $this->assertSame(RecurrenceFrequency::Weekly, $task->recurrence_frequency);
        $this->assertSame(2, (int) $task->recurrence_interval);
        $this->assertSame('2026-12-31', $task->recurrence_ends_at?->toDateString());
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskCreated->value, 'subject_id' => $task->getKey(), 'causer_id' => $rep->getKey()]);

        $this->actingAs($rep)->get(TaskResource::getUrl('view', ['record' => $task]))->assertOk()->assertSee($deal->title);
    }

    #[Test]
    public function the_form_requires_a_title_an_end_after_the_start_and_an_interval_of_at_least_one(): void
    {
        Livewire::actingAs($this->salesRep())
            ->test(CreateTask::class)
            ->fillForm([
                'title' => '',
                'kind' => TaskKind::Meeting->value,
                'starts_at' => '2026-09-10 10:00:00',
                'ends_at' => '2026-09-10 09:00:00',
                'recurrence_frequency' => RecurrenceFrequency::Daily->value,
                'recurrence_interval' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['title' => 'required', 'ends_at', 'recurrence_interval']);

        $this->assertSame(0, Task::query()->count());
    }

    #[Test]
    public function a_record_outside_the_actor_reach_cannot_be_linked(): void
    {
        $rep = $this->salesRep();
        $theirs = Lead::factory()->create(['owner_id' => $this->salesRep()->getKey()]);

        Livewire::actingAs($rep)
            ->test(CreateTask::class)
            ->fillForm(['title' => 'Sneaky', 'lead_id' => $theirs->getKey()])
            ->call('create')
            ->assertHasFormErrors(['lead_id']);

        $this->assertSame(0, Task::query()->count());
    }

    #[Test]
    public function the_status_is_not_editable_from_the_form(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'title' => 'Draft']);

        Livewire::actingAs($rep)
            ->test(EditTask::class, ['record' => $task->getRouteKey()])
            ->assertFormFieldDoesNotExist('status')
            ->assertSee(__('tasks.helpers.status_readonly'))
            ->fillForm(['title' => 'Final', 'status' => TaskStatus::Completed->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $task->refresh();

        $this->assertSame('Final', $fresh->title);
        $this->assertSame(TaskStatus::Pending, $fresh->status);
        $this->assertSame($rep->getKey(), (int) $fresh->assignee_id);
    }

    #[Test]
    public function a_manager_reassigns_from_the_edit_form_through_the_owner_field(): void
    {
        Notification::fake();

        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $task = Task::factory()->create(['assignee_id' => $manager->getKey()]);

        Livewire::actingAs($manager)
            ->test(EditTask::class, ['record' => $task->getRouteKey()])
            ->assertFormSet(['owner_id' => $manager->getKey()])
            ->fillForm(['owner_id' => $rep->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($rep->getKey(), (int) $task->refresh()->assignee_id);

        // The edit is an assignment: ledger row and notice to the new assignee, as the assign action does.
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskAssigned->value, 'subject_id' => $task->getKey(), 'causer_id' => $manager->getKey()]);

        Notification::assertSentTo($rep, RecordAssignedNotification::class);
    }

    #[Test]
    public function the_tabs_split_my_open_tasks_by_due_date_and_status(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $overdue = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => Carbon::parse('2026-09-05 09:00:00')]);
        $today = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => Carbon::parse('2026-09-06 17:00:00')]);
        $upcoming = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => Carbon::parse('2026-09-08 09:00:00')]);
        $completed = Task::factory()->completed()->create(['assignee_id' => $rep->getKey(), 'due_at' => Carbon::parse('2026-09-05 09:00:00')]);
        $cancelled = Task::factory()->cancelled()->create(['assignee_id' => $rep->getKey(), 'due_at' => Carbon::parse('2026-09-05 09:00:00')]);
        $onMyLead = Task::factory()->create([
            'assignee_id' => $other->getKey(),
            'lead_id' => Lead::factory()->create(['owner_id' => $rep->getKey()])->getKey(),
            'due_at' => Carbon::parse('2026-09-05 09:00:00'),
        ]);

        $list = Livewire::actingAs($rep)->test(ListTasks::class);

        $list
            ->assertSet('activeTab', 'my')
            ->assertCanSeeTableRecords([$overdue, $today, $upcoming])
            ->assertCanNotSeeTableRecords([$completed, $cancelled, $onMyLead]);

        $list->set('activeTab', 'today')
            ->assertCanSeeTableRecords([$today])
            ->assertCanNotSeeTableRecords([$overdue, $upcoming, $completed]);

        $list->set('activeTab', 'overdue')
            ->assertCanSeeTableRecords([$overdue, $onMyLead])
            ->assertCanNotSeeTableRecords([$today, $upcoming, $completed, $cancelled]);

        $list->set('activeTab', 'upcoming')
            ->assertCanSeeTableRecords([$upcoming])
            ->assertCanNotSeeTableRecords([$overdue, $today]);

        $list->set('activeTab', 'completed')
            ->assertCanSeeTableRecords([$completed])
            ->assertCanNotSeeTableRecords([$overdue, $today, $upcoming, $cancelled]);

        $list->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$overdue, $today, $upcoming, $completed, $cancelled, $onMyLead]);
    }

    #[Test]
    public function a_task_on_a_readable_record_is_readable_even_when_someone_else_is_assigned(): void
    {
        $rep = $this->salesRep();
        $support = $this->support();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $task = Task::factory()->create(['assignee_id' => $support->getKey(), 'lead_id' => $lead->getKey()]);

        $this->assertTrue($rep->can('view', $task));
        $this->assertFalse($rep->can('update', $task));

        Livewire::actingAs($rep)
            ->test(ListTasks::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$task]);

        $this->actingAs($rep)->get(TaskResource::getUrl('view', ['record' => $task]))->assertOk();
        $this->actingAs($rep)->get(TaskResource::getUrl('edit', ['record' => $task]))->assertForbidden();
    }

    #[Test]
    public function a_task_outside_the_actor_reach_is_hidden_and_its_page_is_not_found(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $theirs = Task::factory()->create([
            'assignee_id' => $other->getKey(),
            'lead_id' => Lead::factory()->create(['owner_id' => $other->getKey()])->getKey(),
        ]);
        $mine = Task::factory()->create(['assignee_id' => $rep->getKey()]);

        $this->assertFalse($rep->can('view', $theirs));
        $this->assertTrue($rep->can('view', $mine));

        Livewire::actingAs($rep)
            ->test(ListTasks::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        $this->actingAs($rep)->get(TaskResource::getUrl('view', ['record' => $theirs]))->assertNotFound();
        $this->actingAs($other)->get(TaskResource::getUrl('view', ['record' => $theirs]))->assertOk();
    }

    #[Test]
    public function team_and_all_levels_widen_the_task_scope(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $outsider = $this->salesRep();
        $repTask = Task::factory()->create(['assignee_id' => $rep->getKey()]);
        $outsiderTask = Task::factory()->create(['assignee_id' => $outsider->getKey()]);
        $unassigned = Task::factory()->unassigned()->create();

        $this->assertTrue($manager->can('view', $repTask));
        $this->assertTrue($manager->can('update', $repTask));
        $this->assertFalse($manager->can('view', $outsiderTask));
        $this->assertTrue($manager->can('view', $unassigned));

        Livewire::actingAs($manager)
            ->test(ListTasks::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$repTask, $unassigned])
            ->assertCanNotSeeTableRecords([$outsiderTask]);

        foreach ([$this->support(), $this->readOnly(), $this->admin()] as $wide) {
            $this->assertTrue($wide->can('view', $outsiderTask));

            Livewire::actingAs($wide)
                ->test(ListTasks::class)
                ->set('activeTab', 'all')
                ->assertCanSeeTableRecords([$repTask, $outsiderTask, $unassigned]);
        }
    }

    #[Test]
    public function completing_from_the_table_takes_a_note_and_writes_the_timeline_entry(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'deal_id' => $deal->getKey()]);

        Livewire::actingAs($rep)
            ->test(ListTasks::class)
            ->assertTableActionVisible('complete', $task)
            ->assertTableActionVisible('cancel', $task)
            ->assertTableActionHidden('reopen', $task)
            ->callTableAction('complete', $task, data: ['completion_note' => 'Signed and filed'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('tasks.notifications.completed'));

        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
        $this->assertNotNull($task->completed_at);

        $activity = Activity::query()->where('task_id', $task->getKey())->firstOrFail();

        $this->assertSame($deal->getKey(), (int) $activity->deal_id);
        $this->assertSame('Signed and filed', $activity->payload['note'] ?? null);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskCompleted->value, 'subject_id' => $task->getKey(), 'causer_id' => $rep->getKey()]);
    }

    #[Test]
    public function reopen_is_offered_only_once_the_task_is_closed(): void
    {
        $rep = $this->salesRep();
        $completed = Task::factory()->completed()->create(['assignee_id' => $rep->getKey()]);
        $cancelled = Task::factory()->cancelled()->create(['assignee_id' => $rep->getKey()]);

        $list = Livewire::actingAs($rep)
            ->test(ListTasks::class)
            ->set('activeTab', 'all')
            ->assertTableActionVisible('reopen', $completed)
            ->assertTableActionVisible('reopen', $cancelled)
            ->assertTableActionHidden('complete', $completed)
            ->assertTableActionHidden('cancel', $completed);

        $list
            ->callTableAction('reopen', $completed)
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('tasks.notifications.reopened'));

        $this->assertSame(TaskStatus::Pending, $completed->refresh()->status);
        $this->assertNull($completed->completed_at);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskReopened->value, 'subject_id' => $completed->getKey()]);

        Livewire::actingAs($rep)
            ->test(ViewTask::class, ['record' => $cancelled->getRouteKey()])
            ->assertActionVisible('reopen')
            ->assertActionHidden('complete')
            ->callAction('reopen')
            ->assertHasNoActionErrors();

        $this->assertSame(TaskStatus::Pending, $cancelled->refresh()->status);
    }

    #[Test]
    public function cancelling_from_the_view_page_is_audited(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(ViewTask::class, ['record' => $task->getRouteKey()])
            ->assertActionVisible('cancel')
            ->callAction('cancel')
            ->assertHasNoActionErrors()
            ->assertNotified(__('tasks.notifications.cancelled'));

        $this->assertSame(TaskStatus::Cancelled, $task->refresh()->status);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskCancelled->value, 'subject_id' => $task->getKey(), 'causer_id' => $rep->getKey()]);
    }

    #[Test]
    public function a_manager_assigns_within_the_team_and_a_rep_holds_no_assign_key(): void
    {
        Notification::fake();

        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $colleague = $this->salesRep($team);
        $task = Task::factory()->create(['assignee_id' => $rep->getKey()]);

        $this->assertTrue($manager->can('assign', $task));
        $this->assertFalse($rep->can('assign', $task));

        Livewire::actingAs($rep)
            ->test(ListTasks::class)
            ->assertTableActionHidden('assign', $task);

        Livewire::actingAs($manager)
            ->test(ListTasks::class)
            ->set('activeTab', 'all')
            ->assertTableActionVisible('assign', $task)
            ->callTableAction('assign', $task, data: ['owner_id' => $colleague->getKey()])
            ->assertHasNoTableActionErrors();

        $this->assertSame($colleague->getKey(), (int) $task->refresh()->assignee_id);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskAssigned->value, 'subject_id' => $task->getKey(), 'causer_id' => $manager->getKey()]);

        Notification::assertSentTo($colleague, RecordAssignedNotification::class);
    }

    #[Test]
    public function bulk_complete_finishes_the_open_tasks_the_actor_may_complete(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $outsider = $this->salesRep();
        $inTeam = Task::factory()->create(['assignee_id' => $rep->getKey()]);
        $alreadyDone = Task::factory()->completed()->create(['assignee_id' => $rep->getKey()]);
        $outside = Task::factory()->create(['assignee_id' => $outsider->getKey()]);

        Livewire::actingAs($this->admin())
            ->test(ListTasks::class)
            ->set('activeTab', 'all')
            ->callTableBulkAction('complete', [$inTeam, $alreadyDone, $outside])
            ->assertHasNoTableBulkActionErrors()
            ->assertNotified(__('tasks.notifications.bulk_completed', ['count' => 2]));

        $this->assertSame(TaskStatus::Completed, $inTeam->refresh()->status);
        $this->assertSame(TaskStatus::Completed, $outside->refresh()->status);
        $this->assertSame(TaskStatus::Completed, $alreadyDone->refresh()->status);

        $this->assertTrue($manager->can('complete', $inTeam));
        $this->assertFalse($manager->can('complete', $outside));
    }

    #[Test]
    public function the_navigation_badge_counts_my_open_tasks_due_today_or_overdue(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();

        $this->actingAs($rep);

        $this->assertNull(TaskResource::getNavigationBadge());

        $today = Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => Carbon::parse('2026-09-06 18:00:00')]);
        Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => Carbon::parse('2026-09-08 09:00:00')]);
        Task::factory()->completed()->create(['assignee_id' => $rep->getKey(), 'due_at' => Carbon::parse('2026-09-01 09:00:00')]);
        Task::factory()->create(['assignee_id' => $other->getKey(), 'due_at' => Carbon::parse('2026-09-01 09:00:00')]);

        $this->assertSame('1', TaskResource::getNavigationBadge());
        $this->assertSame('warning', TaskResource::getNavigationBadgeColor());

        Task::factory()->create(['assignee_id' => $rep->getKey(), 'due_at' => Carbon::parse('2026-09-05 09:00:00')]);

        $this->assertSame('2', TaskResource::getNavigationBadge());
        $this->assertSame('danger', TaskResource::getNavigationBadgeColor());

        app(TaskService::class)->complete($today, $rep);

        $this->assertSame('1', TaskResource::getNavigationBadge());
    }

    #[Test]
    public function the_permission_matrix_gates_create_update_and_delete(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $support = $this->support();
        $readOnly = $this->readOnly();
        $mine = Task::factory()->create(['assignee_id' => $rep->getKey()]);
        $theirs = Task::factory()->create(['assignee_id' => $other->getKey()]);

        $this->assertTrue($rep->can('create', Task::class));
        $this->assertTrue($support->can('create', Task::class));
        $this->assertFalse($readOnly->can('create', Task::class));

        $this->assertTrue($rep->can('update', $mine));
        $this->assertTrue($rep->can('delete', $mine));
        $this->assertTrue($rep->can('restore', $mine));
        $this->assertFalse($rep->can('forceDelete', $mine));
        $this->assertFalse($rep->can('update', $theirs));
        $this->assertFalse($rep->can('delete', $theirs));

        $this->assertTrue($support->can('update', $theirs));
        $this->assertFalse($support->can('delete', $theirs));
        $this->assertFalse($support->can('assign', $theirs));

        $this->assertTrue($readOnly->can('view', $theirs));
        $this->assertFalse($readOnly->can('update', $theirs));
        $this->assertFalse($readOnly->can('complete', $theirs));

        $this->actingAs($readOnly)->get(TaskResource::getUrl('create'))->assertForbidden();
        $this->actingAs($support)->get(TaskResource::getUrl('create'))->assertOk();

        Livewire::actingAs($readOnly)
            ->test(ListTasks::class)
            ->set('activeTab', 'all')
            ->assertActionHidden('create')
            ->assertTableActionHidden('complete', $theirs)
            ->assertTableActionHidden('edit', $theirs)
            ->assertTableActionHidden('assign', $theirs)
            ->assertCanSeeTableRecords([$mine, $theirs]);
    }

    #[Test]
    public function a_rep_deletes_and_restores_their_own_task(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(ViewTask::class, ['record' => $task->getRouteKey()])
            ->callAction('delete')
            ->assertHasNoActionErrors();

        $this->assertSoftDeleted('tasks', ['id' => $task->getKey()]);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskDeleted->value, 'subject_id' => $task->getKey()]);

        Livewire::actingAs($rep)
            ->test(ListTasks::class)
            ->set('activeTab', 'all')
            ->assertCanNotSeeTableRecords([$task])
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$task])
            ->assertTableActionHidden('complete', $task)
            ->callTableBulkAction('restore', [$task])
            ->assertHasNoTableBulkActionErrors();

        $this->assertNull($task->fresh()?->deleted_at);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskRestored->value, 'subject_id' => $task->getKey()]);
    }
}
