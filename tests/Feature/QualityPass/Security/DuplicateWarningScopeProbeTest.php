<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Security;

use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Security probe: the live "possible duplicate" hint (A-11) must stay inside
 * the actor's visibility scope (D-4).
 *
 * DuplicateFinder queries leads, contacts and accounts without the
 * RecordVisibilityResolver, and DuplicateWarning prints the matched names.
 * A sales rep, who may only see their own records, can type any email or
 * phone into the create form and read back the name of whoever owns it —
 * an enumeration oracle over every colleague's pipeline.
 */
final class DuplicateWarningScopeProbeTest extends TestCase
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
    public function a_rep_creating_a_lead_is_not_shown_names_of_records_outside_their_scope(): void
    {
        $rep = $this->salesRep();
        $colleague = $this->salesRep();

        Lead::factory()->create([
            'owner_id' => $colleague->getKey(),
            'first_name' => 'Zubaida',
            'last_name' => 'Hiddenlead',
            'email' => 'secret.buyer@example.com',
        ]);
        Contact::factory()->create([
            'owner_id' => $colleague->getKey(),
            'first_name' => 'Yasmeen',
            'last_name' => 'Hiddencontact',
            'email' => 'secret.buyer@example.com',
        ]);

        $this->assertFalse($rep->can('view', Lead::query()->where('last_name', 'Hiddenlead')->firstOrFail()), 'precondition: the rep cannot read the lead');

        Livewire::actingAs($rep)
            ->test(CreateLead::class)
            ->fillForm(['email' => 'secret.buyer@example.com'])
            ->assertDontSee('Hiddenlead')
            ->assertDontSee('Hiddencontact');
    }

    #[Test]
    public function a_rep_creating_an_account_is_not_shown_names_of_accounts_outside_their_scope(): void
    {
        $rep = $this->salesRep();
        $colleague = $this->salesRep();

        Account::factory()->create([
            'owner_id' => $colleague->getKey(),
            'name' => 'Confidential Holdings',
            'phone' => '0551234567',
        ]);

        Livewire::actingAs($rep)
            ->test(CreateAccount::class)
            ->fillForm(['phone' => '0551234567'])
            // The parent-account picker leaks the name on its own (see
            // RelationPickerScopeProbeTest), so the warning itself is asserted.
            ->assertDontSee(__('merge.warnings.possible_duplicates'), escape: false);
    }
}
