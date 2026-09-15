<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\ActivityLogEvent;
use App\Enums\CloseReasonKind;
use App\Filament\Resources\DealCloseReasons\DealCloseReasonResource;
use App\Filament\Resources\DealCloseReasons\Pages\CreateDealCloseReason;
use App\Filament\Resources\DealCloseReasons\Pages\EditDealCloseReason;
use App\Filament\Resources\DealCloseReasons\Pages\ListDealCloseReasons;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Services\Deals\DealCloseService;
use Database\Seeders\DealCloseReasonSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class DealCloseReasonResourceTest extends TestCase
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
    public function admins_list_close_reasons_and_sales_managers_are_refused(): void
    {
        $admin = $this->admin();
        $manager = $this->salesManager();
        $reason = $this->makeReason(CloseReasonKind::Lost, 'Competitor', 'منافس');

        $this->actingAs($admin)->get(DealCloseReasonResource::getUrl('index'))->assertOk();
        $this->actingAs($admin)->get(DealCloseReasonResource::getUrl('create'))->assertOk();

        $this->actingAs($manager)->get(DealCloseReasonResource::getUrl('index'))->assertForbidden();
        $this->actingAs($manager)->get(DealCloseReasonResource::getUrl('create'))->assertForbidden();

        Livewire::actingAs($admin)->test(ListDealCloseReasons::class)->assertCanSeeTableRecords([$reason]);
    }

    #[Test]
    public function a_close_reason_is_created_with_its_kind_and_audited(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateDealCloseReason::class)
            ->fillForm([
                'kind' => CloseReasonKind::Won->value,
                'name_ar' => 'الجودة',
                'name_en' => 'Quality',
                'is_active' => true,
                'sort' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $reason = DealCloseReason::query()->where('name_en', 'Quality')->firstOrFail();

        $this->assertSame(CloseReasonKind::Won, $reason->kind);
        $this->assertSame('الجودة', $reason->name_ar);
        $this->assertTrue($reason->is_active);
        $this->assertSame(3, $reason->sort);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupCreated->value,
            'subject_type' => DealCloseReason::class,
            'subject_id' => $reason->getKey(),
        ]);
    }

    #[Test]
    public function an_admin_edits_a_close_reason(): void
    {
        $admin = $this->admin();
        $reason = $this->makeReason(CloseReasonKind::Lost, 'Timing', 'التوقيت');

        Livewire::actingAs($admin)
            ->test(EditDealCloseReason::class, ['record' => $reason->getRouteKey()])
            ->assertSchemaStateSet([
                'kind' => CloseReasonKind::Lost,
                'name_en' => 'Timing',
            ])
            ->fillForm([
                'name_ar' => 'التوقيت غير مناسب',
                'name_en' => 'Bad timing',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reason->refresh();

        $this->assertSame('Bad timing', $reason->name_en);
        $this->assertSame('التوقيت غير مناسب', $reason->name_ar);
        $this->assertFalse($reason->is_active);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupUpdated->value,
            'subject_type' => DealCloseReason::class,
            'subject_id' => $reason->getKey(),
        ]);
    }

    #[Test]
    public function the_kind_and_both_names_are_required(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateDealCloseReason::class)
            ->fillForm(['kind' => null, 'name_ar' => '', 'name_en' => ''])
            ->call('create')
            ->assertHasFormErrors(['kind' => 'required', 'name_ar' => 'required', 'name_en' => 'required']);
    }

    #[Test]
    public function names_are_unique_within_a_kind(): void
    {
        $admin = $this->admin();
        $this->makeReason(CloseReasonKind::Lost, 'Price', 'السعر');

        Livewire::actingAs($admin)
            ->test(CreateDealCloseReason::class)
            ->fillForm([
                'kind' => CloseReasonKind::Lost->value,
                'name_ar' => 'السعر',
                'name_en' => 'Price',
            ])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'unique', 'name_en' => 'unique']);
    }

    #[Test]
    public function the_same_name_may_exist_for_a_win_and_a_loss(): void
    {
        $admin = $this->admin();
        $this->makeReason(CloseReasonKind::Lost, 'Price', 'السعر');

        Livewire::actingAs($admin)
            ->test(CreateDealCloseReason::class)
            ->fillForm([
                'kind' => CloseReasonKind::Won->value,
                'name_ar' => 'السعر',
                'name_en' => 'Price',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, DealCloseReason::query()->where('name_en', 'Price')->count());
        $this->assertDatabaseHas('deal_close_reasons', ['kind' => CloseReasonKind::Won->value, 'name_en' => 'Price']);
        $this->assertDatabaseHas('deal_close_reasons', ['kind' => CloseReasonKind::Lost->value, 'name_en' => 'Price']);
    }

    #[Test]
    public function editing_a_reason_ignores_its_own_names_but_not_a_siblings(): void
    {
        $admin = $this->admin();
        $reason = $this->makeReason(CloseReasonKind::Lost, 'Price', 'السعر');
        $this->makeReason(CloseReasonKind::Lost, 'Competitor', 'منافس');

        Livewire::actingAs($admin)
            ->test(EditDealCloseReason::class, ['record' => $reason->getRouteKey()])
            ->fillForm(['sort' => 5])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::actingAs($admin)
            ->test(EditDealCloseReason::class, ['record' => $reason->getRouteKey()])
            ->fillForm(['name_ar' => 'منافس', 'name_en' => 'Competitor'])
            ->call('save')
            ->assertHasFormErrors(['name_ar' => 'unique', 'name_en' => 'unique']);
    }

    #[Test]
    public function the_database_enforces_the_per_kind_uniqueness(): void
    {
        $this->makeReason(CloseReasonKind::Won, 'Quality', 'الجودة');

        $this->expectException(QueryException::class);

        DealCloseReason::factory()->won()->create(['name_en' => 'Quality', 'name_ar' => 'جودة عالية']);
    }

    #[Test]
    public function the_database_rejects_an_unknown_kind(): void
    {
        $this->expectException(QueryException::class);

        DB::table('deal_close_reasons')->insert([
            'kind' => 'unknown',
            'name_ar' => 'غير معروف',
            'name_en' => 'Unknown',
            'is_active' => true,
            'sort' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function the_display_name_follows_the_locale(): void
    {
        $reason = $this->makeReason(CloseReasonKind::Lost, 'No budget', 'لا توجد ميزانية');

        app()->setLocale('ar');
        $this->assertSame('لا توجد ميزانية', $reason->display_name);

        app()->setLocale('en');
        $this->assertSame('No budget', $reason->display_name);

        app()->setLocale('ar');
    }

    #[Test]
    public function the_table_filters_by_kind_and_orders_by_sort(): void
    {
        $admin = $this->admin();
        $won = $this->makeReason(CloseReasonKind::Won, 'Relationship', 'العلاقة', 2);
        $lost = $this->makeReason(CloseReasonKind::Lost, 'No response', 'لا يوجد رد', 1);

        Livewire::actingAs($admin)
            ->test(ListDealCloseReasons::class)
            ->assertCanSeeTableRecords([$lost, $won], inOrder: true)
            ->filterTable('kind', CloseReasonKind::Won->value)
            ->assertCanSeeTableRecords([$won])
            ->assertCanNotSeeTableRecords([$lost]);
    }

    #[Test]
    public function an_admin_deletes_a_close_reason_permanently_from_the_edit_page(): void
    {
        $admin = $this->admin();
        $reason = $this->makeReason(CloseReasonKind::Won, 'Features', 'المزايا');

        Livewire::actingAs($admin)
            ->test(EditDealCloseReason::class, ['record' => $reason->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('deal_close_reasons', ['id' => $reason->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupDeleted->value,
            'subject_type' => DealCloseReason::class,
            'subject_id' => $reason->getKey(),
        ]);
    }

    #[Test]
    public function the_seeder_creates_the_defaults_idempotently(): void
    {
        $this->seed(DealCloseReasonSeeder::class);
        $this->seed(DealCloseReasonSeeder::class);

        $this->assertSame(9, DealCloseReason::query()->count());
        $this->assertSame(4, DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->count());
        $this->assertSame(5, DealCloseReason::query()->where('kind', CloseReasonKind::Lost->value)->count());

        $this->assertDatabaseHas('deal_close_reasons', ['kind' => 'won', 'name_ar' => 'السعر', 'name_en' => 'Price', 'sort' => 1]);
        $this->assertDatabaseHas('deal_close_reasons', ['kind' => 'won', 'name_ar' => 'الجودة', 'name_en' => 'Quality', 'sort' => 4]);
        $this->assertDatabaseHas('deal_close_reasons', ['kind' => 'lost', 'name_ar' => 'السعر', 'name_en' => 'Price', 'sort' => 1]);
        $this->assertDatabaseHas('deal_close_reasons', ['kind' => 'lost', 'name_ar' => 'لا يوجد رد', 'name_en' => 'No response', 'sort' => 5, 'is_active' => true]);
    }

    #[Test]
    public function the_seeder_keeps_an_administrators_edits(): void
    {
        $this->seed(DealCloseReasonSeeder::class);

        DealCloseReason::query()
            ->where('kind', CloseReasonKind::Lost->value)
            ->where('name_en', 'Timing')
            ->update(['name_ar' => 'توقيت غير مناسب', 'is_active' => false]);

        $this->seed(DealCloseReasonSeeder::class);

        $this->assertSame(9, DealCloseReason::query()->count());
        $this->assertDatabaseHas('deal_close_reasons', ['kind' => 'lost', 'name_en' => 'Timing', 'name_ar' => 'توقيت غير مناسب', 'is_active' => false]);
    }

    #[Test]
    public function roles_without_settings_manage_are_refused_at_the_livewire_level(): void
    {
        $manager = $this->salesManager();
        $rep = $this->salesRep();
        $reason = $this->makeReason(CloseReasonKind::Lost, 'Competitor', 'منافس');

        Livewire::actingAs($manager)->test(ListDealCloseReasons::class)->assertForbidden();
        Livewire::actingAs($manager)->test(CreateDealCloseReason::class)->assertForbidden();
        Livewire::actingAs($manager)->test(EditDealCloseReason::class, ['record' => $reason->getRouteKey()])->assertForbidden();

        Livewire::actingAs($rep)->test(ListDealCloseReasons::class)->assertForbidden();
        Livewire::actingAs($rep)->test(CreateDealCloseReason::class)->assertForbidden();
        Livewire::actingAs($rep)->test(EditDealCloseReason::class, ['record' => $reason->getRouteKey()])->assertForbidden();

        $this->assertDatabaseHas('deal_close_reasons', ['id' => $reason->getKey()]);
    }

    #[Test]
    public function admins_reorder_close_reasons_by_dragging(): void
    {
        $admin = $this->admin();
        $price = $this->makeReason(CloseReasonKind::Lost, 'Price', 'السعر', 1);
        $competitor = $this->makeReason(CloseReasonKind::Lost, 'Competitor', 'منافس', 2);
        $timing = $this->makeReason(CloseReasonKind::Lost, 'Timing', 'التوقيت', 3);

        Livewire::actingAs($admin)
            ->test(ListDealCloseReasons::class)
            ->call('reorderTable', [$timing->getKey(), $price->getKey(), $competitor->getKey()]);

        $this->assertSame(1, $timing->refresh()->sort);
        $this->assertSame(2, $price->refresh()->sort);
        $this->assertSame(3, $competitor->refresh()->sort);
    }

    #[Test]
    public function admins_bulk_delete_close_reasons(): void
    {
        $admin = $this->admin();
        $first = $this->makeReason(CloseReasonKind::Won, 'Price', 'السعر');
        $second = $this->makeReason(CloseReasonKind::Lost, 'Competitor', 'منافس');

        Livewire::actingAs($admin)
            ->test(ListDealCloseReasons::class)
            ->callTableBulkAction('delete', [$first, $second]);

        $this->assertDatabaseMissing('deal_close_reasons', ['id' => $first->getKey()]);
        $this->assertDatabaseMissing('deal_close_reasons', ['id' => $second->getKey()]);
    }

    #[Test]
    public function a_reason_recorded_on_a_closed_deal_is_never_deleted_even_when_the_deal_is_trashed(): void
    {
        $this->seedLookups();
        $admin = $this->admin();
        $used = $this->makeReason(CloseReasonKind::Won, 'Long relationship', 'علاقة طويلة');
        $unused = $this->makeReason(CloseReasonKind::Won, 'Fast delivery', 'سرعة التسليم');
        $deal = Deal::factory()->create(['owner_id' => $admin->getKey()]);
        app(DealCloseService::class)->win($deal, $used, $admin);

        $assertRefused = function () use ($admin, $used): void {
            $this->assertFalse($admin->can('delete', $used));

            Livewire::actingAs($admin)
                ->test(EditDealCloseReason::class, ['record' => $used->getRouteKey()])
                ->assertActionHidden('delete');
        };

        $assertRefused();

        $deal->refresh()->delete();

        $assertRefused();

        Livewire::actingAs($admin)
            ->test(ListDealCloseReasons::class)
            ->callTableBulkAction('delete', [$used, $unused]);

        $this->assertDatabaseHas('deal_close_reasons', ['id' => $used->getKey()]);
        $this->assertDatabaseMissing('deal_close_reasons', ['id' => $unused->getKey()]);
    }

    #[Test]
    public function the_seeder_keeps_a_reason_whose_english_name_was_renamed(): void
    {
        $this->seed(DealCloseReasonSeeder::class);

        DealCloseReason::query()
            ->where('kind', CloseReasonKind::Lost->value)
            ->where('name_en', 'Timing')
            ->update(['name_en' => 'Bad timing']);

        $this->seed(DealCloseReasonSeeder::class);

        $this->assertSame(9, DealCloseReason::query()->count());
        $this->assertDatabaseHas('deal_close_reasons', ['kind' => 'lost', 'name_en' => 'Bad timing', 'name_ar' => 'التوقيت']);
        $this->assertDatabaseMissing('deal_close_reasons', ['kind' => 'lost', 'name_en' => 'Timing']);
    }

    #[Test]
    public function the_seeder_recreates_a_deleted_default(): void
    {
        $this->seed(DealCloseReasonSeeder::class);

        DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->where('name_en', 'Quality')->delete();

        $this->seed(DealCloseReasonSeeder::class);

        $this->assertSame(9, DealCloseReason::query()->count());
        $this->assertDatabaseHas('deal_close_reasons', ['kind' => 'won', 'name_en' => 'Quality', 'name_ar' => 'الجودة', 'sort' => 4]);
    }

    private function makeReason(CloseReasonKind $kind, string $nameEn, string $nameAr, int $sort = 0): DealCloseReason
    {
        return DealCloseReason::factory()->create([
            'kind' => $kind,
            'name_en' => $nameEn,
            'name_ar' => $nameAr,
            'sort' => $sort,
        ]);
    }
}
