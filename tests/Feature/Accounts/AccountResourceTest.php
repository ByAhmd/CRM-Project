<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Enums\AccountType;
use App\Enums\ActivityLogEvent;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Models\Account;
use App\Models\Industry;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class AccountResourceTest extends TestCase
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
    public function every_role_with_view_any_reaches_the_list_and_others_are_refused(): void
    {
        $rep = $this->salesRep();
        $readOnly = $this->readOnly();
        $noRole = $this->salesRep();
        $noRole->syncRoles([]);

        $this->actingAs($rep)->get(AccountResource::getUrl('index'))->assertOk();
        $this->actingAs($readOnly)->get(AccountResource::getUrl('index'))->assertOk();
        $this->actingAs($readOnly)->get(AccountResource::getUrl('create'))->assertForbidden();
        $this->actingAs($noRole->refresh())->get(AccountResource::getUrl('index'))->assertForbidden();
    }

    #[Test]
    public function a_rep_creates_an_account_owned_by_themselves_with_industry_and_tags(): void
    {
        $rep = $this->salesRep();
        $industry = Industry::factory()->create();
        $tag = Tag::factory()->create();

        Livewire::actingAs($rep)
            ->test(CreateAccount::class)
            ->fillForm([
                'name' => 'شركة الأفق للتجارة',
                'type' => AccountType::Prospect->value,
                'industry_id' => $industry->getKey(),
                'email' => 'Info@Horizon.SA',
                'phone' => '011 234 5678',
                'city' => 'الرياض',
                'country' => 'SA',
                'tags' => [$tag->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $account = Account::query()->where('name', 'شركة الأفق للتجارة')->firstOrFail();

        $this->assertSame($rep->getKey(), $account->owner_id);
        $this->assertSame($rep->getKey(), $account->created_by);
        $this->assertSame('info@horizon.sa', $account->email_normalized);
        $this->assertSame('+966112345678', $account->phone_normalized);
        $this->assertSame($industry->getKey(), $account->industry_id);
        $this->assertTrue($account->tags->contains($tag));
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::AccountCreated->value, 'subject_id' => $account->getKey()]);
    }

    #[Test]
    public function the_name_is_required_and_the_website_must_be_a_url(): void
    {
        Livewire::actingAs($this->salesRep())
            ->test(CreateAccount::class)
            ->fillForm(['name' => '', 'website' => 'not a url'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'website' => 'url']);
    }

    #[Test]
    public function a_rep_sees_and_edits_only_their_own_accounts(): void
    {
        $rep = $this->salesRep();
        $colleague = $this->salesRep();

        $mine = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $theirs = Account::factory()->create(['owner_id' => $colleague->getKey()]);

        Livewire::actingAs($rep)
            ->test(ListAccounts::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        $this->actingAs($rep)->get(AccountResource::getUrl('view', ['record' => $theirs]))->assertNotFound();
        $this->actingAs($rep)->get(AccountResource::getUrl('edit', ['record' => $theirs]))->assertNotFound();
        $this->actingAs($rep)->get(AccountResource::getUrl('view', ['record' => $mine]))->assertOk();

        Livewire::actingAs($rep)
            ->test(EditAccount::class, ['record' => $mine->getRouteKey()])
            ->fillForm(['name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed', $mine->refresh()->name);
    }

    #[Test]
    public function a_manager_sees_the_team_and_an_admin_sees_everything(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $member = $this->salesRep($team);
        $outsider = $this->salesRep();
        $admin = $this->admin();

        $memberAccount = Account::factory()->create(['owner_id' => $member->getKey()]);
        $outsiderAccount = Account::factory()->create(['owner_id' => $outsider->getKey()]);

        Livewire::actingAs($manager)
            ->test(ListAccounts::class)
            ->assertCanSeeTableRecords([$memberAccount])
            ->assertCanNotSeeTableRecords([$outsiderAccount]);

        Livewire::actingAs($admin)
            ->test(ListAccounts::class)
            ->assertCanSeeTableRecords([$memberAccount, $outsiderAccount]);
    }

    #[Test]
    public function a_rep_cannot_change_the_owner_through_the_form_but_a_manager_can(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(EditAccount::class, ['record' => $account->getRouteKey()])
            ->assertFormFieldHidden('owner_id')
            ->assertSee($rep->name)
            ->fillForm(['name' => 'Still mine'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($rep->getKey(), $account->refresh()->owner_id);

        Livewire::actingAs($manager)
            ->test(EditAccount::class, ['record' => $account->getRouteKey()])
            ->assertFormFieldVisible('owner_id')
            ->fillForm(['owner_id' => $manager->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($manager->getKey(), $account->refresh()->owner_id);
    }

    #[Test]
    public function a_rep_cannot_delete_but_a_manager_can_and_the_record_is_soft_deleted(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);

        $this->assertFalse($rep->can('delete', $account));
        $this->assertTrue($manager->can('delete', $account));

        Livewire::actingAs($manager)
            ->test(ViewAccount::class, ['record' => $account->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted('accounts', ['id' => $account->getKey()]);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::AccountDeleted->value, 'subject_id' => $account->getKey()]);
    }

    #[Test]
    public function the_list_tabs_filter_by_type(): void
    {
        $admin = $this->admin();
        $prospect = Account::factory()->create();
        $customer = Account::factory()->customer()->create();

        Livewire::actingAs($admin)
            ->test(ListAccounts::class)
            ->set('activeTab', AccountType::Customer->value)
            ->assertCanSeeTableRecords([$customer])
            ->assertCanNotSeeTableRecords([$prospect]);
    }

    #[Test]
    public function global_search_only_returns_visible_accounts(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();

        $mine = Account::factory()->create(['owner_id' => $rep->getKey(), 'name' => 'Alpha Visible']);
        Account::factory()->create(['owner_id' => $other->getKey(), 'name' => 'Alpha Hidden']);

        $this->actingAs($rep);

        $results = AccountResource::getGlobalSearchEloquentQuery()->where('name', 'like', 'Alpha%')->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()?->is($mine));
        $this->assertSame(['name', 'email', 'phone', 'city'], AccountResource::getGloballySearchableAttributes());
    }
}
