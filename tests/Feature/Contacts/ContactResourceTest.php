<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Enums\ActivityLogEvent;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Models\Account;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class ContactResourceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
    }

    #[Test]
    public function a_rep_creates_a_contact_at_an_account_and_it_is_normalised_and_audited(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(CreateContact::class)
            ->fillForm([
                'first_name' => 'سارة',
                'last_name' => 'العتيبي',
                'account_id' => $account->getKey(),
                'job_title' => 'مديرة المشتريات',
                'email' => 'Sara@Example.com',
                'mobile' => '0551234567',
                'preferred_locale' => 'ar',
                'is_primary' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $contact = Contact::query()->where('email', 'Sara@Example.com')->firstOrFail();

        $this->assertSame('سارة العتيبي', $contact->full_name);
        $this->assertSame('sara@example.com', $contact->email_normalized);
        $this->assertSame('+966551234567', $contact->phone_normalized);
        $this->assertTrue($contact->is_primary);
        $this->assertSame($rep->getKey(), $contact->owner_id);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::ContactCreated->value, 'subject_id' => $contact->getKey()]);
    }

    #[Test]
    public function only_one_contact_per_account_can_be_primary(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $first = Contact::factory()->create(['account_id' => $account->getKey(), 'owner_id' => $rep->getKey(), 'is_primary' => true]);
        $second = Contact::factory()->create(['account_id' => $account->getKey(), 'owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(EditContact::class, ['record' => $second->getRouteKey()])
            ->fillForm(['is_primary' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($second->refresh()->is_primary);
        $this->assertFalse($first->refresh()->is_primary);
    }

    #[Test]
    public function names_are_required_and_urls_are_validated(): void
    {
        Livewire::actingAs($this->salesRep())
            ->test(CreateContact::class)
            ->fillForm(['first_name' => '', 'last_name' => '', 'linkedin_url' => 'nope', 'email' => 'not-an-email'])
            ->call('create')
            ->assertHasFormErrors(['first_name' => 'required', 'last_name' => 'required', 'linkedin_url' => 'url', 'email' => 'email']);
    }

    #[Test]
    public function visibility_follows_ownership(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $mine = Contact::factory()->create(['owner_id' => $rep->getKey()]);
        $theirs = Contact::factory()->create(['owner_id' => $other->getKey()]);

        Livewire::actingAs($rep)
            ->test(ListContacts::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        $this->actingAs($rep)->get(ContactResource::getUrl('view', ['record' => $theirs]))->assertNotFound();
        $this->actingAs($rep)->get(ContactResource::getUrl('edit', ['record' => $mine]))->assertOk();
        $this->actingAs($rep)->get(ContactResource::getUrl('edit', ['record' => $theirs]))->assertNotFound();
        $this->actingAs($this->readOnly())->get(ContactResource::getUrl('view', ['record' => $theirs]))->assertOk();
    }

    #[Test]
    public function a_manager_reaches_the_team_contacts_but_not_another_teams(): void
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

        $this->actingAs($manager)->get(ContactResource::getUrl('view', ['record' => $inTeam]))->assertOk();
        $this->actingAs($manager)->get(ContactResource::getUrl('edit', ['record' => $inTeam]))->assertOk();
        $this->actingAs($manager)->get(ContactResource::getUrl('view', ['record' => $outside]))->assertNotFound();
        $this->actingAs($manager)->get(ContactResource::getUrl('edit', ['record' => $outside]))->assertNotFound();
    }

    #[Test]
    public function the_bulk_delete_soft_deletes_only_in_scope_contacts_and_is_not_offered_to_a_rep(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $member = $this->salesRep($team);
        $outsider = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));
        $inTeam = Contact::factory()->create(['owner_id' => $member->getKey()]);
        $outside = Contact::factory()->create(['owner_id' => $outsider->getKey()]);

        // Livewire::actingAs switches the user for every component, so each actor's component runs to completion first.
        Livewire::actingAs($member)
            ->test(ListContacts::class)
            ->assertTableBulkActionHidden('delete');

        $this->assertNotSoftDeleted('contacts', ['id' => $inTeam->getKey()]);

        Livewire::actingAs($manager)
            ->test(ListContacts::class)
            ->callTableBulkAction('delete', [$inTeam, $outside])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSoftDeleted('contacts', ['id' => $inTeam->getKey()]);
        $this->assertNotSoftDeleted('contacts', ['id' => $outside->getKey()]);
    }

    #[Test]
    public function contacts_can_be_created_from_the_account_page_and_inherit_the_account(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(ContactsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
            ->callTableAction('create', data: [
                'first_name' => 'خالد',
                'last_name' => 'السالم',
                'mobile' => '0559876543',
            ])
            ->assertHasNoTableActionErrors();

        $contact = Contact::query()->where('last_name', 'السالم')->firstOrFail();

        $this->assertSame($account->getKey(), $contact->account_id);
        $this->assertSame($rep->getKey(), $contact->owner_id);
    }

    #[Test]
    public function global_search_matches_the_account_name_and_stays_in_scope(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $account = Account::factory()->create(['name' => 'Zeta Holdings', 'owner_id' => $rep->getKey()]);
        $mine = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);
        Contact::factory()->create(['owner_id' => $other->getKey(), 'account_id' => $account->getKey()]);

        $this->actingAs($rep);

        $this->assertContains('account.name', ContactResource::getGloballySearchableAttributes());
        $visible = ContactResource::getGlobalSearchEloquentQuery()->pluck('id')->all();

        $this->assertSame([$mine->getKey()], $visible);
    }
}
