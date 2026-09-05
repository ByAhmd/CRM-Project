<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\ActivityLogEvent;
use App\Filament\Resources\Industries\IndustryResource;
use App\Filament\Resources\Industries\Pages\CreateIndustry;
use App\Filament\Resources\Industries\Pages\EditIndustry;
use App\Filament\Resources\Industries\Pages\ListIndustries;
use App\Models\Account;
use App\Models\Industry;
use Database\Seeders\IndustrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class IndustryResourceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
    }

    private function makeIndustry(string $nameEn = 'Technology', string $nameAr = 'التقنية', int $sort = 0): Industry
    {
        return Industry::factory()->create([
            'name_en' => $nameEn,
            'name_ar' => $nameAr,
            'sort' => $sort,
        ]);
    }

    #[Test]
    public function admins_list_industries_sorted_by_their_order(): void
    {
        $admin = $this->admin();
        $second = $this->makeIndustry('Retail', 'تجارة التجزئة', 2);
        $first = $this->makeIndustry('Technology', 'التقنية', 1);

        $this->actingAs($admin)->get(IndustryResource::getUrl('index'))->assertOk();

        Livewire::actingAs($admin)
            ->test(ListIndustries::class)
            ->assertCanSeeTableRecords([$first, $second], inOrder: true);
    }

    #[Test]
    public function sales_managers_are_refused_on_index_create_and_edit(): void
    {
        $manager = $this->salesManager();
        $industry = $this->makeIndustry();

        $this->actingAs($manager)->get(IndustryResource::getUrl('index'))->assertForbidden();
        $this->actingAs($manager)->get(IndustryResource::getUrl('create'))->assertForbidden();
        $this->actingAs($manager)->get(IndustryResource::getUrl('edit', ['record' => $industry]))->assertForbidden();
    }

    #[Test]
    public function roles_without_settings_manage_are_refused_at_the_livewire_level(): void
    {
        $manager = $this->salesManager();
        $rep = $this->salesRep();
        $industry = $this->makeIndustry();

        Livewire::actingAs($manager)->test(ListIndustries::class)->assertForbidden();
        Livewire::actingAs($rep)->test(ListIndustries::class)->assertForbidden();
        Livewire::actingAs($rep)->test(CreateIndustry::class)->assertForbidden();
        Livewire::actingAs($rep)->test(EditIndustry::class, ['record' => $industry->getRouteKey()])->assertForbidden();

        $this->assertDatabaseHas('industries', ['id' => $industry->getKey()]);
    }

    #[Test]
    public function roles_without_settings_manage_cannot_reorder(): void
    {
        $rep = $this->salesRep();
        $first = $this->makeIndustry('Technology', 'التقنية', 1);
        $second = $this->makeIndustry('Retail', 'تجارة التجزئة', 2);

        $this->assertFalse($rep->can('reorder', Industry::class));

        Livewire::actingAs($rep)
            ->test(ListIndustries::class)
            ->assertForbidden();

        $this->assertSame(1, $first->refresh()->sort);
        $this->assertSame(2, $second->refresh()->sort);
    }

    #[Test]
    public function an_industry_is_created_with_both_names_and_audited(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateIndustry::class)
            ->fillForm([
                'name_ar' => 'الرعاية الصحية',
                'name_en' => 'Healthcare',
                'is_active' => true,
                'sort' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $industry = Industry::query()->where('name_en', 'Healthcare')->firstOrFail();

        $this->assertSame('الرعاية الصحية', $industry->name_ar);
        $this->assertTrue($industry->is_active);
        $this->assertSame(3, $industry->sort);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupCreated->value,
            'subject_type' => Industry::class,
            'subject_id' => $industry->getKey(),
        ]);
    }

    #[Test]
    public function an_industry_is_edited_and_the_change_is_audited(): void
    {
        $admin = $this->admin();
        $industry = $this->makeIndustry();

        Livewire::actingAs($admin)
            ->test(EditIndustry::class, ['record' => $industry->getRouteKey()])
            ->fillForm([
                'name_en' => 'Information Technology',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $industry->refresh();

        $this->assertSame('Information Technology', $industry->name_en);
        $this->assertFalse($industry->is_active);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupUpdated->value,
            'subject_type' => Industry::class,
            'subject_id' => $industry->getKey(),
        ]);
    }

    #[Test]
    public function both_names_are_required_and_unique(): void
    {
        $admin = $this->admin();
        $this->makeIndustry('Technology', 'التقنية');

        Livewire::actingAs($admin)
            ->test(CreateIndustry::class)
            ->fillForm(['name_ar' => 'التقنية', 'name_en' => ''])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'unique', 'name_en' => 'required']);

        Livewire::actingAs($admin)
            ->test(CreateIndustry::class)
            ->fillForm(['name_ar' => '', 'name_en' => 'Technology'])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'required', 'name_en' => 'unique']);
    }

    #[Test]
    public function an_industry_keeps_its_own_names_when_edited(): void
    {
        $admin = $this->admin();
        $industry = $this->makeIndustry('Technology', 'التقنية');

        Livewire::actingAs($admin)
            ->test(EditIndustry::class, ['record' => $industry->getRouteKey()])
            ->fillForm(['name_ar' => 'التقنية', 'name_en' => 'Technology', 'sort' => 5])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(5, $industry->refresh()->sort);
    }

    #[Test]
    public function the_display_name_follows_the_locale(): void
    {
        $industry = $this->makeIndustry('Technology', 'التقنية');

        app()->setLocale('ar');
        $this->assertSame('التقنية', $industry->display_name);

        app()->setLocale('en');
        $this->assertSame('Technology', $industry->display_name);

        app()->setLocale('ar');
    }

    #[Test]
    public function an_industry_is_deleted_permanently_and_audited(): void
    {
        $admin = $this->admin();
        $industry = $this->makeIndustry();

        Livewire::actingAs($admin)
            ->test(EditIndustry::class, ['record' => $industry->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('industries', ['id' => $industry->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupDeleted->value,
            'subject_type' => Industry::class,
            'subject_id' => $industry->getKey(),
        ]);
    }

    #[Test]
    public function an_industry_used_by_an_account_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $used = $this->makeIndustry('Technology', 'التقنية');
        $unused = $this->makeIndustry('Retail', 'تجارة التجزئة');
        Account::factory()->create(['industry_id' => $used->getKey(), 'owner_id' => $admin->getKey()]);

        $this->assertFalse($admin->can('delete', $used));
        $this->assertTrue($admin->can('delete', $unused));
        $this->assertTrue($admin->can('deleteAny', Industry::class));

        Livewire::actingAs($admin)
            ->test(EditIndustry::class, ['record' => $used->getRouteKey()])
            ->assertActionHidden('delete');

        $this->assertDatabaseHas('industries', ['id' => $used->getKey()]);
    }

    #[Test]
    public function an_industry_used_by_a_soft_deleted_account_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $used = $this->makeIndustry('Technology', 'التقنية');
        $account = Account::factory()->create(['industry_id' => $used->getKey(), 'owner_id' => $admin->getKey()]);
        $account->delete();

        $this->assertFalse($admin->can('delete', $used));

        Livewire::actingAs($admin)
            ->test(EditIndustry::class, ['record' => $used->getRouteKey()])
            ->assertActionHidden('delete');

        $this->assertDatabaseHas('industries', ['id' => $used->getKey()]);
    }

    #[Test]
    public function bulk_delete_skips_industries_used_by_accounts(): void
    {
        $admin = $this->admin();
        $used = $this->makeIndustry('Technology', 'التقنية');
        $unused = $this->makeIndustry('Retail', 'تجارة التجزئة');
        Account::factory()->create(['industry_id' => $used->getKey(), 'owner_id' => $admin->getKey()]);

        Livewire::actingAs($admin)
            ->test(ListIndustries::class)
            ->callTableBulkAction('delete', [$used, $unused]);

        $this->assertDatabaseHas('industries', ['id' => $used->getKey()]);
        $this->assertDatabaseMissing('industries', ['id' => $unused->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupDeleted->value,
            'subject_type' => Industry::class,
            'subject_id' => $unused->getKey(),
        ]);
    }

    #[Test]
    public function the_table_is_reorderable_by_sort(): void
    {
        $admin = $this->admin();
        $first = $this->makeIndustry('Technology', 'التقنية', 1);
        $second = $this->makeIndustry('Retail', 'تجارة التجزئة', 2);

        Livewire::actingAs($admin)
            ->test(ListIndustries::class)
            ->call('reorderTable', [(string) $second->getKey(), (string) $first->getKey()]);

        $this->assertSame(1, $second->refresh()->sort);
        $this->assertSame(2, $first->refresh()->sort);
    }

    #[Test]
    public function the_seeder_creates_the_defaults_idempotently(): void
    {
        $this->seed(IndustrySeeder::class);

        $this->assertSame(15, Industry::query()->count());
        $this->assertDatabaseHas('industries', ['name_en' => 'Technology', 'name_ar' => 'التقنية', 'is_active' => true, 'sort' => 1]);
        $this->assertDatabaseHas('industries', ['name_en' => 'Other', 'name_ar' => 'أخرى', 'sort' => 15]);

        Industry::query()->where('name_en', 'Technology')->update(['name_ar' => 'تقنية المعلومات']);

        $this->seed(IndustrySeeder::class);

        $this->assertSame(15, Industry::query()->count());
        $this->assertDatabaseHas('industries', ['name_en' => 'Technology', 'name_ar' => 'تقنية المعلومات']);
    }

    #[Test]
    public function the_seeder_skips_a_default_whose_english_name_was_renamed(): void
    {
        $this->seed(IndustrySeeder::class);

        Industry::query()->where('name_en', 'Technology')->update(['name_en' => 'Information Technology']);

        $this->seed(IndustrySeeder::class);

        $this->assertSame(15, Industry::query()->count());
        $this->assertDatabaseHas('industries', ['name_en' => 'Information Technology', 'name_ar' => 'التقنية']);
        $this->assertDatabaseMissing('industries', ['name_en' => 'Technology']);
    }
}
