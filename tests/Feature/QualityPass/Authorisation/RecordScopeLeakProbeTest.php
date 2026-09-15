<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Authorisation;

use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\ContactsRelationManager as AccountContactsRelationManager;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\ContactsRelationManager as DealContactsRelationManager;
use App\Livewire\RecordTimeline;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Services\Notes\NoteService;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Authorisation audit probes: paths that surface owned records (or their
 * content) outside the viewer's reach (D-4).
 */
final class RecordScopeLeakProbeTest extends TestCase
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
    public function the_account_contacts_panel_lists_only_contacts_the_viewer_may_read(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $mine = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);
        $foreign = Contact::factory()->create(['owner_id' => $other->getKey(), 'account_id' => $account->getKey()]);

        $this->assertFalse($rep->can('view', $foreign), 'precondition');

        Livewire::actingAs($rep)
            ->test(AccountContactsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    #[Test]
    public function the_deal_contacts_panel_does_not_expose_a_contact_outside_the_viewers_reach(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);
        $foreign = Contact::factory()->create(['owner_id' => $other->getKey(), 'account_id' => $account->getKey(), 'email' => 'private.buyer@example.com']);
        $deal->contacts()->attach($foreign->getKey());

        $this->assertFalse($rep->can('view', $foreign), 'precondition');

        Livewire::actingAs($rep)
            ->test(DealContactsRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->assertDontSee('private.buyer@example.com');
    }

    #[Test]
    public function the_contact_form_refuses_an_account_outside_the_actors_reach(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $foreignAccount = Account::factory()->create(['owner_id' => $other->getKey(), 'name' => 'Foreign Holdings']);

        $this->assertFalse($rep->can('view', $foreignAccount), 'precondition');

        Livewire::actingAs($rep)
            ->test(CreateContact::class)
            ->fillForm([
                'first_name' => 'Noura',
                'last_name' => 'Alharbi',
                'account_id' => $foreignAccount->getKey(),
            ])
            ->call('create')
            ->assertHasFormErrors(['account_id']);
    }

    #[Test]
    public function the_contact_form_does_not_offer_accounts_outside_the_actors_reach(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => null]);
        Account::factory()->create(['owner_id' => $other->getKey(), 'name' => 'Foreign Holdings']);

        $page = Livewire::actingAs($rep)
            ->test(EditContact::class, ['record' => $contact->getRouteKey()])
            ->instance();
        $this->assertInstanceOf(EditContact::class, $page);

        $select = $page->getSchema('form')?->getComponent('account_id');
        $this->assertInstanceOf(Select::class, $select);

        $options = $select->getSearchResults('Foreign');
        $this->assertNotContains('Foreign Holdings', $options, 'the account picker lists every account in the organisation');
    }

    #[Test]
    public function an_account_timeline_does_not_show_notes_written_on_deals_outside_reach(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $foreignDeal = Deal::factory()->create(['owner_id' => $other->getKey(), 'account_id' => $account->getKey()]);

        $this->assertTrue($rep->can('view', $account), 'precondition');
        $this->assertFalse($rep->can('view', $foreignDeal), 'precondition');

        app(NoteService::class)->create($foreignDeal, $other, 'Confidential discount ceiling is 42 percent');

        Livewire::actingAs($rep)
            ->test(RecordTimeline::class, ['subjectType' => Account::class, 'subjectId' => $account->getKey()])
            ->assertOk()
            ->assertDontSee('Confidential discount ceiling');
    }
}
