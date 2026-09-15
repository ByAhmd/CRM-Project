<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Schema;

use App\Enums\CrmRole;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealProduct;
use App\Models\Industry;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Probe items 4 and 5: the design's answer to "a lookup in use" is to
 * deactivate (or soft delete) it rather than delete it. A record that still
 * references the retired row must stay editable — an unrelated edit must not
 * be blocked by a validation error on a field the user did not touch, and
 * must not silently drop the reference.
 */
final class RetiredReferencesOnEditProbeTest extends TestCase
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
    public function a_deal_with_a_line_of_a_deactivated_product_can_still_be_renamed(): void
    {
        $admin = $this->admin();
        $deal = Deal::factory()->create(['owner_id' => $admin->getKey()]);
        $product = Product::factory()->create(['name_en' => 'Legacy licence', 'name_ar' => 'ترخيص قديم']);
        DealProduct::factory()->create(['deal_id' => $deal->getKey(), 'product_id' => $product->getKey()]);
        $product->update(['is_active' => false]);

        Livewire::actingAs($admin)
            ->test(EditDeal::class, ['record' => $deal->getRouteKey()])
            ->fillForm(['title' => 'Renamed deal'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed deal', $deal->refresh()->title);
        $this->assertDatabaseHas('deal_products', ['deal_id' => $deal->getKey(), 'product_id' => $product->getKey()]);
    }

    #[Test]
    public function a_deal_with_a_line_of_a_soft_deleted_product_can_still_be_renamed(): void
    {
        $admin = $this->admin();
        $deal = Deal::factory()->create(['owner_id' => $admin->getKey()]);
        $product = Product::factory()->create(['name_en' => 'Retired licence', 'name_ar' => 'ترخيص متوقف']);
        DealProduct::factory()->create(['deal_id' => $deal->getKey(), 'product_id' => $product->getKey()]);
        $product->delete();

        Livewire::actingAs($admin)
            ->test(EditDeal::class, ['record' => $deal->getRouteKey()])
            ->fillForm(['title' => 'Renamed deal'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed deal', $deal->refresh()->title);
    }

    #[Test]
    public function a_deal_whose_source_was_deactivated_can_still_be_renamed_and_keeps_the_source(): void
    {
        $admin = $this->admin();
        $source = LeadSource::factory()->create(['name_en' => 'Old fair', 'name_ar' => 'معرض قديم']);
        $deal = Deal::factory()->create(['owner_id' => $admin->getKey(), 'lead_source_id' => $source->getKey()]);
        $source->update(['is_active' => false]);

        Livewire::actingAs($admin)
            ->test(EditDeal::class, ['record' => $deal->getRouteKey()])
            ->fillForm(['title' => 'Renamed deal'])
            ->call('save')
            ->assertHasNoFormErrors();

        $deal->refresh();
        $this->assertSame('Renamed deal', $deal->title);
        $this->assertSame($source->getKey(), $deal->lead_source_id);
    }

    #[Test]
    public function a_lead_whose_source_was_deactivated_can_still_be_edited_and_keeps_the_source(): void
    {
        $admin = $this->admin();
        $source = LeadSource::factory()->create(['name_en' => 'Old fair', 'name_ar' => 'معرض قديم']);
        $lead = Lead::factory()->create(['owner_id' => $admin->getKey(), 'lead_source_id' => $source->getKey()]);
        $source->update(['is_active' => false]);

        Livewire::actingAs($admin)
            ->test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->fillForm(['job_title' => 'CTO'])
            ->call('save')
            ->assertHasNoFormErrors();

        $lead->refresh();
        $this->assertSame('CTO', $lead->job_title);
        $this->assertSame($source->getKey(), $lead->lead_source_id);
    }

    #[Test]
    public function an_account_whose_industry_was_deactivated_can_still_be_edited_and_keeps_the_industry(): void
    {
        $admin = $this->admin();
        $industry = Industry::factory()->create(['name_en' => 'Old sector', 'name_ar' => 'قطاع قديم']);
        $account = Account::factory()->create(['owner_id' => $admin->getKey(), 'industry_id' => $industry->getKey()]);
        $industry->update(['is_active' => false]);

        Livewire::actingAs($admin)
            ->test(EditAccount::class, ['record' => $account->getRouteKey()])
            ->fillForm(['name' => 'Renamed account'])
            ->call('save')
            ->assertHasNoFormErrors();

        $account->refresh();
        $this->assertSame('Renamed account', $account->name);
        $this->assertSame($industry->getKey(), $account->industry_id);
    }

    #[Test]
    public function a_contact_of_a_soft_deleted_account_can_still_be_edited_and_keeps_the_account(): void
    {
        $admin = $this->admin();
        $account = Account::factory()->create(['owner_id' => $admin->getKey()]);
        $contact = Contact::factory()->create(['account_id' => $account->getKey(), 'owner_id' => $admin->getKey()]);
        $account->delete();

        Livewire::actingAs($admin)
            ->test(EditContact::class, ['record' => $contact->getRouteKey()])
            ->fillForm(['job_title' => 'CFO'])
            ->call('save')
            ->assertHasNoFormErrors();

        $contact->refresh();
        $this->assertSame('CFO', $contact->job_title);
        $this->assertSame($account->getKey(), $contact->account_id);
    }

    #[Test]
    public function a_user_in_a_deactivated_team_can_still_be_edited_and_keeps_the_team(): void
    {
        $admin = $this->admin();
        $team = $this->makeTeam('Dormant Team', 'فريق خامل');
        $user = $this->makeUser(CrmRole::SalesRep, ['name' => 'Team Member'], $team);
        $team->update(['is_active' => false]);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['name' => 'Renamed Member'])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertSame('Renamed Member', $user->name);
        $this->assertSame($team->getKey(), $user->team_id);
    }

    #[Test]
    public function a_lead_owned_by_a_soft_deleted_user_can_still_be_edited_by_an_admin_and_keeps_its_owner(): void
    {
        $admin = $this->admin();
        $leaver = $this->makeUser(CrmRole::SalesRep, ['name' => 'Former Rep']);
        $lead = Lead::factory()->create(['owner_id' => $leaver->getKey()]);
        $leaver->delete();

        Livewire::actingAs($admin)
            ->test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->fillForm(['job_title' => 'CTO'])
            ->call('save')
            ->assertHasNoFormErrors();

        $lead->refresh();
        $this->assertSame('CTO', $lead->job_title);
        $this->assertSame($leaver->getKey(), $lead->owner_id);
    }

    #[Test]
    public function a_deal_of_a_soft_deleted_account_can_still_be_renamed_and_keeps_account_and_contact(): void
    {
        $admin = $this->admin();
        $account = Account::factory()->create(['owner_id' => $admin->getKey()]);
        $contact = Contact::factory()->create(['account_id' => $account->getKey(), 'owner_id' => $admin->getKey()]);
        $deal = Deal::factory()->create(['owner_id' => $admin->getKey(), 'account_id' => $account->getKey(), 'contact_id' => $contact->getKey()]);
        $account->delete();

        Livewire::actingAs($admin)
            ->test(EditDeal::class, ['record' => $deal->getRouteKey()])
            ->fillForm(['title' => 'Renamed deal'])
            ->call('save')
            ->assertHasNoFormErrors();

        $deal->refresh();
        $this->assertSame('Renamed deal', $deal->title);
        $this->assertSame($account->getKey(), $deal->account_id);
        $this->assertSame($contact->getKey(), $deal->contact_id);
    }
}
