<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\AccountTasksRelationManager;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Contacts\RelationManagers\ContactTasksRelationManager;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\DealTasksRelationManager;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Leads\RelationManagers\LeadTasksRelationManager;
use App\Models\Account;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The tasks managers on the lead, contact, account and deal pages
 * (decisions D-4, A-10): a task added there is pre-linked to the owner
 * record, the status actions work in place, and the manager is offered only
 * to users who may list tasks and read the record.
 */
final class TasksRelationManagerTest extends TestCase
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
    public function the_four_resources_register_their_tasks_manager(): void
    {
        $this->assertContains(LeadTasksRelationManager::class, LeadResource::getRelations());
        $this->assertContains(ContactTasksRelationManager::class, ContactResource::getRelations());
        $this->assertContains(AccountTasksRelationManager::class, AccountResource::getRelations());
        $this->assertContains(DealTasksRelationManager::class, DealResource::getRelations());
    }

    #[Test]
    public function a_rep_adds_a_task_from_the_deal_page_pre_linked_to_the_deal(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);

        $this->actingAs($rep);
        $this->assertTrue(DealTasksRelationManager::canViewForRecord($deal, ViewDeal::class));

        Livewire::actingAs($rep)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->assertTableActionVisible('create')
            ->callTableAction('create', data: [
                'title' => 'Send the proposal',
                'kind' => TaskKind::FollowUp->value,
                'priority' => TaskPriority::Urgent->value,
                'due_at' => '2026-09-08 10:00:00',
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('tasks.notifications.added'));

        $task = Task::query()->where('title', 'Send the proposal')->firstOrFail();

        $this->assertSame($deal->getKey(), (int) $task->deal_id);
        $this->assertSame($rep->getKey(), (int) $task->assignee_id);
        $this->assertSame($rep->getKey(), (int) $task->created_by);
        $this->assertSame(TaskKind::FollowUp, $task->kind);
        $this->assertSame(TaskPriority::Urgent, $task->priority);
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertTrue($deal->tasks->first()?->is($task));
    }

    #[Test]
    public function the_task_is_completed_from_the_deal_page_and_lands_on_the_deal_timeline(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'deal_id' => $deal->getKey(), 'title' => 'Call the buyer']);

        Livewire::actingAs($rep)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->assertCanSeeTableRecords([$task])
            ->assertTableActionVisible('complete', $task)
            ->assertTableActionHidden('reopen', $task)
            ->callTableAction('complete', $task, data: ['completion_note' => 'Agreed on the price'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('tasks.notifications.completed'));

        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);

        $activity = Activity::query()->where('task_id', $task->getKey())->firstOrFail();

        $this->assertSame($deal->getKey(), (int) $activity->deal_id);
        $this->assertSame('Call the buyer', $activity->subject);
        $this->assertTrue($deal->activities->first()?->is($activity));
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskCompleted->value, 'subject_id' => $task->getKey(), 'causer_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->assertTableActionHidden('complete', $task)
            ->assertTableActionVisible('reopen', $task)
            ->callTableAction('reopen', $task)
            ->assertHasNoTableActionErrors();

        $this->assertSame(TaskStatus::Pending, $task->refresh()->status);
    }

    #[Test]
    public function the_edit_modal_goes_through_the_service_and_leaves_the_status_alone(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'lead_id' => $lead->getKey(), 'title' => 'Draft']);

        Livewire::actingAs($rep)
            ->test(LeadTasksRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertTableActionVisible('edit', $task)
            ->callTableAction('edit', $task, data: ['title' => 'Final', 'priority' => TaskPriority::Low->value])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('tasks.notifications.updated'));

        $fresh = $task->refresh();

        $this->assertSame('Final', $fresh->title);
        $this->assertSame(TaskPriority::Low, $fresh->priority);
        $this->assertSame(TaskStatus::Pending, $fresh->status);
        $this->assertSame($lead->getKey(), (int) $fresh->lead_id);
        $this->assertSame($rep->getKey(), (int) $fresh->assignee_id);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskUpdated->value, 'subject_id' => $task->getKey(), 'causer_id' => $rep->getKey()]);
    }

    #[Test]
    public function the_contact_and_account_managers_link_the_task_to_their_own_record(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);

        Livewire::actingAs($rep)
            ->test(ContactTasksRelationManager::class, ['ownerRecord' => $contact, 'pageClass' => ViewContact::class])
            ->callTableAction('create', data: ['title' => 'Contact task', 'kind' => TaskKind::Task->value, 'priority' => TaskPriority::Medium->value])
            ->assertHasNoTableActionErrors();

        Livewire::actingAs($rep)
            ->test(AccountTasksRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
            ->callTableAction('create', data: ['title' => 'Account task', 'kind' => TaskKind::Task->value, 'priority' => TaskPriority::Medium->value])
            ->assertHasNoTableActionErrors();

        $contactTask = Task::query()->where('title', 'Contact task')->firstOrFail();
        $accountTask = Task::query()->where('title', 'Account task')->firstOrFail();

        $this->assertSame($contact->getKey(), (int) $contactTask->contact_id);
        $this->assertNull($contactTask->account_id);
        $this->assertSame($account->getKey(), (int) $accountTask->account_id);
        $this->assertNull($accountTask->contact_id);

        Livewire::actingAs($rep)
            ->test(AccountTasksRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
            ->assertCanSeeTableRecords([$accountTask])
            ->assertCanNotSeeTableRecords([$contactTask]);
    }

    #[Test]
    public function the_manager_is_hidden_for_a_user_who_cannot_view_the_subject(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'deal_id' => $deal->getKey()]);

        $this->assertFalse($other->can('view', $deal));
        $this->assertFalse($other->can('view', $task));

        $this->actingAs($other);
        $this->assertFalse(DealTasksRelationManager::canViewForRecord($deal, ViewDeal::class));

        $this->actingAs($rep);
        $this->assertTrue(DealTasksRelationManager::canViewForRecord($deal, ViewDeal::class));
    }

    #[Test]
    public function a_read_only_user_reads_the_tasks_but_sees_none_of_the_write_actions(): void
    {
        $rep = $this->salesRep();
        $readOnly = $this->readOnly();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'deal_id' => $deal->getKey()]);

        $this->actingAs($readOnly);

        $this->assertTrue(DealTasksRelationManager::canViewForRecord($deal, ViewDeal::class));
        $this->assertTrue($readOnly->can('view', $task));
        $this->assertFalse($readOnly->can('create', Task::class));
        $this->assertFalse($readOnly->can('complete', $task));

        Livewire::actingAs($readOnly)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->assertCanSeeTableRecords([$task])
            ->assertTableActionVisible('view', $task)
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit', $task)
            ->assertTableActionHidden('complete', $task)
            ->assertTableActionHidden('cancel', $task)
            ->assertTableActionHidden('assign', $task)
            ->assertTableActionHidden('delete', $task)
            ->assertOk();
    }

    #[Test]
    public function support_adds_and_completes_a_task_on_a_rep_deal_it_may_read(): void
    {
        $rep = $this->salesRep();
        $support = $this->support();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);

        $this->assertTrue($support->can('view', $deal));
        $this->assertFalse($support->can('update', $deal));

        $manager = Livewire::actingAs($support)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->assertTableActionVisible('create')
            ->callTableAction('create', data: ['title' => 'Support follow-up', 'kind' => TaskKind::Call->value, 'priority' => TaskPriority::Medium->value])
            ->assertHasNoTableActionErrors();

        $task = Task::query()->where('title', 'Support follow-up')->firstOrFail();

        $this->assertSame($support->getKey(), (int) $task->assignee_id);
        $this->assertTrue($support->can('complete', $task));
        $this->assertFalse($support->can('delete', $task));

        $manager
            ->assertTableActionHidden('delete', $task)
            ->callTableAction('complete', $task)
            ->assertHasNoTableActionErrors();

        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
    }
}
