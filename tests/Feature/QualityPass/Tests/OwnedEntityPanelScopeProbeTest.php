<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Tests;

use App\Enums\ActivityLogEvent;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Notifications\RecordAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Test-suite audit probe: panel paths of the owned entities that no test
 * under tests/Feature exercises today — lead and contact reassignment through
 * the table actions (only accounts, deals and tasks are covered), the contact
 * list at team level, the edit URL of an out-of-scope lead/contact/deal/task,
 * and the scoped DeleteBulkAction on the four commercial lists.
 */
final class OwnedEntityPanelScopeProbeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    #[Test]
    public function a_manager_reassigns_a_lead_and_a_contact_from_the_list_while_a_rep_never_sees_the_action(): void
    {
        Notification::fake();

        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $teammate = $this->salesRep($team);

        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)->test(ListLeads::class)->set('activeTab', 'all')->assertTableActionHidden('assign', $lead);
        Livewire::actingAs($rep)->test(ListContacts::class)->assertTableActionHidden('assign', $contact);

        Livewire::actingAs($manager)
            ->test(ListLeads::class)
            ->set('activeTab', 'all')
            ->callTableAction('assign', $lead, data: ['owner_id' => $teammate->getKey()])
            ->assertHasNoTableActionErrors();

        Livewire::actingAs($manager)
            ->test(ListContacts::class)
            ->callTableAction('assign', $contact, data: ['owner_id' => $teammate->getKey()])
            ->assertHasNoTableActionErrors();

        $this->assertSame($teammate->getKey(), $lead->refresh()->owner_id);
        $this->assertSame($teammate->getKey(), $contact->refresh()->owner_id);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::LeadAssigned->value, 'subject_id' => $lead->getKey(), 'causer_id' => $manager->getKey()]);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::ContactAssigned->value, 'subject_id' => $contact->getKey(), 'causer_id' => $manager->getKey()]);
        Notification::assertSentToTimes($teammate, RecordAssignedNotification::class, 2);
    }

    #[Test]
    public function a_manager_lists_the_team_contacts_but_not_another_teams(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $member = $this->salesRep($team);
        $outsider = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));

        $inTeam = Contact::factory()->create(['owner_id' => $member->getKey()]);
        $outside = Contact::factory()->create(['owner_id' => $outsider->getKey()]);

        Livewire::actingAs($manager)
            ->test(ListContacts::class)
            ->assertCanSeeTableRecords([$inTeam])
            ->assertCanNotSeeTableRecords([$outside]);

        $this->actingAs($manager)->get(ContactResource::getUrl('view', ['record' => $outside]))->assertNotFound();
        $this->actingAs($manager)->get(ContactResource::getUrl('edit', ['record' => $inTeam]))->assertOk();
    }

    #[Test]
    public function the_edit_page_of_an_out_of_scope_record_is_not_found_for_every_owned_entity(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();

        $lead = Lead::factory()->create(['owner_id' => $other->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $other->getKey()]);
        $deal = Deal::factory()->create(['owner_id' => $other->getKey()]);
        $task = Task::factory()->create(['assignee_id' => $other->getKey()]);

        $this->actingAs($rep)->get(LeadResource::getUrl('edit', ['record' => $lead]))->assertNotFound();
        $this->actingAs($rep)->get(ContactResource::getUrl('edit', ['record' => $contact]))->assertNotFound();
        $this->actingAs($rep)->get(DealResource::getUrl('edit', ['record' => $deal]))->assertNotFound();
        $this->actingAs($rep)->get(TaskResource::getUrl('edit', ['record' => $task]))->assertNotFound();
    }

    #[Test]
    public function the_bulk_delete_of_the_commercial_lists_soft_deletes_only_in_scope_and_is_absent_for_a_rep(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $member = $this->salesRep($team);

        $cases = [
            [ListLeads::class, Lead::factory()->create(['owner_id' => $member->getKey()]), 'leads', true],
            [ListContacts::class, Contact::factory()->create(['owner_id' => $member->getKey()]), 'contacts', false],
            [ListAccounts::class, Account::factory()->create(['owner_id' => $member->getKey()]), 'accounts', false],
            [ListDeals::class, Deal::factory()->create(['owner_id' => $member->getKey()]), 'deals', true],
        ];

        foreach ($cases as [$page, $record, $table, $hasTabs]) {
            // Livewire::actingAs switches the authenticated user for every component, so each
            // actor runs its component to completion before the next one is built.
            $asRep = Livewire::actingAs($member)->test($page);

            if ($hasTabs) {
                $asRep->set('activeTab', 'all');
            }

            // A rep holds no {entity}.delete: the bulk action is not offered at all.
            $asRep->assertTableBulkActionHidden('delete');
            $this->assertNotSoftDeleted($table, ['id' => $record->getKey()]);

            $asManager = Livewire::actingAs($manager)->test($page);

            if ($hasTabs) {
                $asManager->set('activeTab', 'all');
            }

            $asManager->callTableBulkAction('delete', [$record])->assertHasNoTableBulkActionErrors();

            $this->assertSoftDeleted($table, ['id' => $record->getKey()]);
        }
    }

    #[Test]
    public function the_bulk_delete_is_not_offered_to_a_rep_who_holds_no_delete_permission(): void
    {
        $rep = $this->salesRep();
        Lead::factory()->create(['owner_id' => $rep->getKey()]);
        Contact::factory()->create(['owner_id' => $rep->getKey()]);
        Account::factory()->create(['owner_id' => $rep->getKey()]);
        Deal::factory()->create(['owner_id' => $rep->getKey()]);

        $this->assertFalse($rep->can('deleteAny', Lead::class), 'precondition');

        Livewire::actingAs($rep)->test(ListLeads::class)->set('activeTab', 'all')->assertTableBulkActionHidden('delete');
        Livewire::actingAs($rep)->test(ListContacts::class)->assertTableBulkActionHidden('delete');
        Livewire::actingAs($rep)->test(ListAccounts::class)->assertTableBulkActionHidden('delete');
        Livewire::actingAs($rep)->test(ListDeals::class)->set('activeTab', 'all')->assertTableBulkActionHidden('delete');
    }
}
