<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Security;

use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\ContactsRelationManager as AccountContactsRelationManager;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Models\Account;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Security probe: account pickers and account filters must offer only the
 * accounts the actor may read (D-4).
 *
 * The contact form's account Select, the account form's parent Select, the
 * contacts table's account filter and the account constraint of the contact
 * and deal query builders use the bare `account` relationship with
 * preload(): a sales rep receives the name of every account in the
 * organisation, and — because the relationship Select validates against
 * that same unscoped query — may attach a contact to, or parent an account
 * under, an account they cannot see. The account view's contacts panel
 * lists every contact of the account (name, email, mobile) without the
 * resolver, unlike its deals panel. The deal form and SubjectPickers show
 * the scoped pattern these should follow.
 */
final class RelationPickerScopeProbeTest extends TestCase
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
    public function the_contact_form_does_not_offer_accounts_outside_the_reps_scope(): void
    {
        $rep = $this->salesRep();
        $this->hiddenAccount();

        Livewire::actingAs($rep)
            ->test(CreateContact::class)
            ->assertDontSee('Confidential Holdings');
    }

    #[Test]
    public function a_rep_cannot_attach_a_contact_to_an_account_they_cannot_see(): void
    {
        $rep = $this->salesRep();
        $hidden = $this->hiddenAccount();

        $this->assertFalse($rep->can('view', $hidden), 'precondition: the rep cannot read the account');

        Livewire::actingAs($rep)
            ->test(CreateContact::class)
            ->fillForm([
                'first_name' => 'Probe',
                'last_name' => 'Contact',
                'account_id' => $hidden->getKey(),
            ])
            ->call('create');

        $this->assertFalse(
            Contact::query()->where('last_name', 'Contact')->where('account_id', $hidden->getKey())->exists(),
            'a contact was attached to an account outside the rep\'s scope',
        );
    }

    #[Test]
    public function the_parent_account_picker_does_not_offer_accounts_outside_the_reps_scope(): void
    {
        $rep = $this->salesRep();
        $this->hiddenAccount();

        Livewire::actingAs($rep)
            ->test(CreateAccount::class)
            ->assertDontSee('Confidential Holdings');
    }

    #[Test]
    public function the_contacts_list_filters_do_not_list_accounts_outside_the_reps_scope(): void
    {
        $rep = $this->salesRep();
        $this->hiddenAccount();

        Livewire::actingAs($rep)
            ->test(ListContacts::class)
            ->assertDontSee('Confidential Holdings');
    }

    #[Test]
    public function the_contacts_panel_of_an_account_lists_only_contacts_the_viewer_may_read(): void
    {
        $rep = $this->salesRep();
        $colleague = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $hidden = Contact::factory()->create([
            'owner_id' => $colleague->getKey(),
            'account_id' => $account->getKey(),
            'first_name' => 'Private',
            'last_name' => 'Colleaguecontact',
        ]);

        $this->assertFalse($rep->can('view', $hidden), 'precondition: the rep cannot read the contact');

        Livewire::actingAs($rep)
            ->test(AccountContactsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
            ->assertCanNotSeeTableRecords([$hidden]);
    }

    private function hiddenAccount(): Account
    {
        $colleague = $this->salesRep();

        return Account::factory()->create([
            'owner_id' => $colleague->getKey(),
            'name' => 'Confidential Holdings',
        ]);
    }
}
