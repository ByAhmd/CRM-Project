<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Enums\CloseReasonKind;
use App\Enums\DealContactRole;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\CompetitorsRelationManager;
use App\Filament\Resources\Deals\RelationManagers\ContactsRelationManager;
use App\Models\Account;
use App\Models\Competitor;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Services\Deals\DealCloseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The contacts and competitors managers on the deal page (decisions D-6,
 * D-8): attach with pivot data, edit the pivot, detach — all behind the
 * deal's `update` verb.
 */
final class DealRelationManagersTest extends TestCase
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
    public function a_rep_attaches_a_contact_of_the_deal_account_with_a_role_then_edits_and_detaches_it(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);

        $manager = Livewire::actingAs($rep)
            ->test(ContactsRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->assertTableActionVisible('attach')
            ->callTableAction('attach', data: ['recordId' => $contact->getKey(), 'role' => DealContactRole::Champion->value])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('deal_contacts', ['deal_id' => $deal->getKey(), 'contact_id' => $contact->getKey(), 'role' => DealContactRole::Champion->value]);

        $manager
            ->assertCanSeeTableRecords([$contact])
            ->assertSee(DealContactRole::Champion->getLabel())
            ->callTableAction('edit', $contact, data: ['role' => DealContactRole::DecisionMaker->value])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('deal_contacts', ['deal_id' => $deal->getKey(), 'contact_id' => $contact->getKey(), 'role' => DealContactRole::DecisionMaker->value]);

        $manager
            ->callTableAction('detach', $contact)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('deal_contacts', ['deal_id' => $deal->getKey(), 'contact_id' => $contact->getKey()]);
    }

    #[Test]
    public function only_contacts_of_the_deal_account_are_offered(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);
        $elsewhere = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => Account::factory()->create(['owner_id' => $rep->getKey()])->getKey()]);

        Livewire::actingAs($rep)
            ->test(ContactsRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => EditDeal::class])
            ->callTableAction('attach', data: ['recordId' => $elsewhere->getKey(), 'role' => DealContactRole::Influencer->value])
            ->assertHasTableActionErrors(['recordId']);

        $this->assertDatabaseMissing('deal_contacts', ['deal_id' => $deal->getKey(), 'contact_id' => $elsewhere->getKey()]);
    }

    #[Test]
    public function a_deal_without_an_account_offers_the_contacts_within_the_actor_scope(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => null]);
        $mine = Contact::factory()->create(['owner_id' => $rep->getKey()]);
        $theirs = Contact::factory()->create(['owner_id' => $this->salesRep()->getKey()]);

        Livewire::actingAs($rep)
            ->test(ContactsRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => EditDeal::class])
            ->callTableAction('attach', data: ['recordId' => $theirs->getKey()])
            ->assertHasTableActionErrors(['recordId']);

        Livewire::actingAs($rep)
            ->test(ContactsRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => EditDeal::class])
            ->callTableAction('attach', data: ['recordId' => $mine->getKey()])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('deal_contacts', ['deal_id' => $deal->getKey(), 'contact_id' => $mine->getKey(), 'role' => null]);
        $this->assertDatabaseMissing('deal_contacts', ['deal_id' => $deal->getKey(), 'contact_id' => $theirs->getKey()]);
    }

    #[Test]
    public function a_rep_attaches_a_competitor_with_the_winner_flag_and_notes_then_edits_and_detaches_it(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $competitor = Competitor::factory()->create();
        Competitor::factory()->create(['is_active' => false]);

        $manager = Livewire::actingAs($rep)
            ->test(CompetitorsRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->assertTableActionVisible('attach')
            ->callTableAction('attach', data: ['recordId' => $competitor->getKey(), 'is_winner' => true, 'notes' => 'Undercut us on price'])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('deal_competitors', ['deal_id' => $deal->getKey(), 'competitor_id' => $competitor->getKey(), 'is_winner' => 1, 'notes' => 'Undercut us on price']);

        $manager
            ->assertCanSeeTableRecords([$competitor])
            ->callTableAction('edit', $competitor, data: ['is_winner' => false, 'notes' => 'Lost on delivery time'])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('deal_competitors', ['deal_id' => $deal->getKey(), 'competitor_id' => $competitor->getKey(), 'is_winner' => 0, 'notes' => 'Lost on delivery time']);

        $manager
            ->callTableAction('detach', $competitor)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('deal_competitors', ['deal_id' => $deal->getKey(), 'competitor_id' => $competitor->getKey()]);
    }

    #[Test]
    public function an_inactive_competitor_is_not_offered(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $inactive = Competitor::factory()->create(['is_active' => false]);

        Livewire::actingAs($rep)
            ->test(CompetitorsRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => EditDeal::class])
            ->callTableAction('attach', data: ['recordId' => $inactive->getKey()])
            ->assertHasTableActionErrors(['recordId']);

        $this->assertDatabaseMissing('deal_competitors', ['deal_id' => $deal->getKey(), 'competitor_id' => $inactive->getKey()]);
    }

    #[Test]
    public function a_read_only_user_sees_the_managers_but_none_of_the_write_actions(): void
    {
        $rep = $this->salesRep();
        $readOnly = $this->readOnly();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $deal->account_id]);
        $competitor = Competitor::factory()->create();
        $deal->contacts()->attach($contact, ['role' => DealContactRole::Champion->value]);
        $deal->competitors()->attach($competitor, ['is_winner' => false]);

        $this->assertFalse(ContactsRelationManager::canViewForRecord($deal, ViewDeal::class));

        $this->actingAs($readOnly);

        $this->assertTrue(ContactsRelationManager::canViewForRecord($deal, ViewDeal::class));
        $this->assertTrue(CompetitorsRelationManager::canViewForRecord($deal, ViewDeal::class));

        Livewire::actingAs($readOnly)
            ->test(ContactsRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->assertCanSeeTableRecords([$contact])
            ->assertTableActionHidden('attach')
            ->assertTableActionHidden('edit', $contact)
            ->assertTableActionHidden('detach', $contact)
            ->assertOk();

        Livewire::actingAs($readOnly)
            ->test(CompetitorsRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->assertCanSeeTableRecords([$competitor])
            ->assertTableActionHidden('attach')
            ->assertTableActionHidden('edit', $competitor)
            ->assertTableActionHidden('detach', $competitor)
            ->assertOk();
    }

    #[Test]
    public function a_closed_deal_freezes_its_contacts_and_competitors(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $deal->account_id]);
        $deal->contacts()->attach($contact);

        app(DealCloseService::class)->win($deal, DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->firstOrFail(), $rep);

        Livewire::actingAs($rep)
            ->test(ContactsRelationManager::class, ['ownerRecord' => $deal->fresh(), 'pageClass' => ViewDeal::class])
            ->assertCanSeeTableRecords([$contact])
            ->assertTableActionHidden('attach')
            ->assertTableActionHidden('detach', $contact);

        Livewire::actingAs($rep)
            ->test(CompetitorsRelationManager::class, ['ownerRecord' => $deal->fresh(), 'pageClass' => ViewDeal::class])
            ->assertTableActionHidden('attach');
    }
}
