<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\ActivityLogEvent;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class ProductResourceTest extends TestCase
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
    public function an_admin_lists_products(): void
    {
        $admin = $this->admin();
        $product = $this->makeProduct();

        $this->actingAs($admin)->get(ProductResource::getUrl('index'))->assertOk();
        $this->actingAs($admin)->get(ProductResource::getUrl('create'))->assertOk();
        $this->actingAs($admin)->get(ProductResource::getUrl('edit', ['record' => $product]))->assertOk();

        Livewire::actingAs($admin)->test(ListProducts::class)->assertCanSeeTableRecords([$product]);
    }

    #[Test]
    public function a_product_is_created_with_both_names_and_audited(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateProduct::class)
            ->fillForm([
                'code' => 'crm-basic',
                'name_ar' => 'الاشتراك الأساسي',
                'name_en' => 'Basic Subscription',
                'unit_price' => '1250.50',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('name_en', 'Basic Subscription')->firstOrFail();

        $this->assertSame('CRM-BASIC', $product->code);
        $this->assertSame('1250.50', $product->unit_price);
        $this->assertTrue($product->is_active);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupCreated->value,
            'subject_type' => Product::class,
            'subject_id' => $product->getKey(),
        ]);
    }

    #[Test]
    public function an_admin_edits_a_product(): void
    {
        $admin = $this->admin();
        $product = $this->makeProduct();

        Livewire::actingAs($admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSchemaStateSet([
                'code' => $product->code,
                'name_en' => $product->name_en,
            ])
            ->fillForm([
                'name_en' => 'Premium Subscription',
                'unit_price' => '99.99',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();

        $this->assertSame('Premium Subscription', $product->name_en);
        $this->assertSame('99.99', $product->unit_price);
        $this->assertFalse($product->is_active);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupUpdated->value,
            'subject_type' => Product::class,
            'subject_id' => $product->getKey(),
        ]);
    }

    #[Test]
    public function a_sales_manager_lists_products_but_may_not_create_or_edit_them(): void
    {
        $manager = $this->salesManager();
        $product = $this->makeProduct();

        $this->actingAs($manager)->get(ProductResource::getUrl('index'))->assertOk();
        $this->actingAs($manager)->get(ProductResource::getUrl('create'))->assertForbidden();
        $this->actingAs($manager)->get(ProductResource::getUrl('edit', ['record' => $product]))->assertForbidden();

        Livewire::actingAs($manager)->test(ListProducts::class)->assertCanSeeTableRecords([$product]);
    }

    #[Test]
    public function sales_reps_and_read_only_users_list_products(): void
    {
        $product = $this->makeProduct();

        foreach ([$this->salesRep(), $this->readOnly()] as $user) {
            $this->actingAs($user)->get(ProductResource::getUrl('index'))->assertOk();
            $this->actingAs($user)->get(ProductResource::getUrl('create'))->assertForbidden();

            Livewire::actingAs($user)->test(ListProducts::class)->assertCanSeeTableRecords([$product]);
        }
    }

    #[Test]
    public function both_names_are_required_and_unique(): void
    {
        $admin = $this->admin();
        $this->makeProduct('Basic Subscription', 'الاشتراك الأساسي');

        Livewire::actingAs($admin)
            ->test(CreateProduct::class)
            ->fillForm(['name_ar' => 'الاشتراك الأساسي', 'name_en' => ''])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'unique', 'name_en' => 'required']);

        Livewire::actingAs($admin)
            ->test(CreateProduct::class)
            ->fillForm(['name_ar' => '', 'name_en' => 'Basic Subscription'])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'required', 'name_en' => 'unique']);
    }

    #[Test]
    public function the_code_is_optional_but_unique_among_live_products(): void
    {
        $admin = $this->admin();
        $this->makeProduct('Basic Subscription', 'الاشتراك الأساسي', 'PRD-0001');

        Livewire::actingAs($admin)
            ->test(CreateProduct::class)
            ->fillForm([
                'code' => 'prd-0001',
                'name_ar' => 'الاشتراك المميز',
                'name_en' => 'Premium Subscription',
                'unit_price' => '10',
            ])
            ->call('create')
            ->assertHasFormErrors(['code' => 'unique']);

        Livewire::actingAs($admin)
            ->test(CreateProduct::class)
            ->fillForm([
                'code' => '',
                'name_ar' => 'الاشتراك المميز',
                'name_en' => 'Premium Subscription',
                'unit_price' => '10',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::actingAs($admin)
            ->test(CreateProduct::class)
            ->fillForm([
                'code' => '',
                'name_ar' => 'الاشتراك المؤسسي',
                'name_en' => 'Enterprise Subscription',
                'unit_price' => '10',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Product::query()->whereNull('code')->count());
    }

    #[Test]
    public function a_soft_deleted_namesake_is_reported_for_restoring_rather_than_recreated(): void
    {
        $admin = $this->admin();
        $deleted = $this->makeProduct('Basic Subscription', 'الاشتراك الأساسي', 'PRD-0001');
        $deleted->delete();

        // The live-record unique rules ignore trashed rows while the database
        // indexes are global, so the dedicated guards must reject the code and
        // the names with their own messages instead of the index throwing.
        Livewire::actingAs($admin)
            ->test(CreateProduct::class)
            ->fillForm([
                'code' => ' prd-0001 ',
                'name_ar' => 'الاشتراك المميز',
                'name_en' => 'Premium Subscription',
                'unit_price' => '10',
            ])
            ->call('create')
            ->assertHasFormErrors(['code'])
            ->assertHasNoFormErrors(['code' => 'unique', 'name_ar', 'name_en']);

        Livewire::actingAs($admin)
            ->test(CreateProduct::class)
            ->fillForm([
                'code' => '',
                'name_ar' => 'الاشتراك الأساسي',
                'name_en' => 'Basic Subscription',
                'unit_price' => '10',
            ])
            ->call('create')
            ->assertHasFormErrors(['name_ar', 'name_en'])
            ->assertHasNoFormErrors(['code', 'name_ar' => 'unique', 'name_en' => 'unique']);

        $this->assertSame(1, Product::withTrashed()->count());

        $deleted->restore();

        $this->assertNull($deleted->refresh()->deleted_at);
        $this->assertDatabaseHas('products', ['id' => $deleted->getKey(), 'code' => 'PRD-0001', 'deleted_at' => null]);

        // The restored row may keep its own code and names when edited.
        Livewire::actingAs($admin)
            ->test(EditProduct::class, ['record' => $deleted->getRouteKey()])
            ->fillForm(['code' => 'PRD-0001', 'name_en' => 'Basic Subscription'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    #[Test]
    public function the_trashed_filter_reveals_deleted_products(): void
    {
        $admin = $this->admin();
        $active = $this->makeProduct('Basic Subscription', 'الاشتراك الأساسي', 'PRD-0001');
        $deleted = $this->makeProduct('Premium Subscription', 'الاشتراك المميز', 'PRD-0002');
        $deleted->delete();

        Livewire::actingAs($admin)
            ->test(ListProducts::class)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$deleted])
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$deleted])
            ->assertCanNotSeeTableRecords([$active])
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$active, $deleted]);
    }

    #[Test]
    public function a_sales_manager_may_view_but_not_delete_or_restore_products(): void
    {
        $manager = $this->salesManager();
        $product = $this->makeProduct();

        $this->assertTrue($manager->can('view', $product));
        $this->assertFalse($manager->can('delete', $product));
        $this->assertFalse($manager->can('restore', $product));
        $this->assertFalse($manager->can('deleteAny', Product::class));
        $this->assertFalse($manager->can('restoreAny', Product::class));

        Livewire::actingAs($manager)
            ->test(ListProducts::class)
            ->assertCanSeeTableRecords([$product])
            ->assertTableBulkActionHidden('delete')
            ->assertTableBulkActionHidden('restore');

        $this->assertDatabaseHas('products', ['id' => $product->getKey(), 'deleted_at' => null]);
    }

    #[Test]
    public function the_unit_price_must_be_a_non_negative_number_with_at_most_two_decimals(): void
    {
        $admin = $this->admin();

        $attempt = fn (string $price) => Livewire::actingAs($admin)
            ->test(CreateProduct::class)
            ->fillForm([
                'name_ar' => 'الاشتراك الأساسي',
                'name_en' => 'Basic Subscription',
                'unit_price' => $price,
            ])
            ->call('create');

        $attempt('abc')->assertHasFormErrors(['unit_price' => 'numeric']);
        $attempt('-1')->assertHasFormErrors(['unit_price' => 'min']);
        $attempt('10.123')->assertHasFormErrors(['unit_price' => 'decimal']);
        $attempt('10.12')->assertHasNoFormErrors();

        $this->assertSame('10.12', Product::query()->where('name_en', 'Basic Subscription')->firstOrFail()->unit_price);
    }

    #[Test]
    public function the_display_name_follows_the_locale(): void
    {
        $product = $this->makeProduct('Basic Subscription', 'الاشتراك الأساسي');

        app()->setLocale('ar');
        $this->assertSame('الاشتراك الأساسي', $product->display_name);

        app()->setLocale('en');
        $this->assertSame('Basic Subscription', $product->display_name);

        app()->setLocale('ar');
    }

    #[Test]
    public function a_product_is_soft_deleted_and_restorable(): void
    {
        $admin = $this->admin();
        $product = $this->makeProduct();

        Livewire::actingAs($admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted('products', ['id' => $product->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupDeleted->value,
            'subject_type' => Product::class,
            'subject_id' => $product->getKey(),
        ]);

        Livewire::actingAs($admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('restore');

        $this->assertNull($product->refresh()->deleted_at);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupRestored->value,
            'subject_type' => Product::class,
            'subject_id' => $product->getKey(),
        ]);
    }

    private function makeProduct(string $nameEn = 'Basic Subscription', string $nameAr = 'الاشتراك الأساسي', ?string $code = 'PRD-0001'): Product
    {
        return Product::factory()->create([
            'name_en' => $nameEn,
            'name_ar' => $nameAr,
            'code' => $code,
            'unit_price' => '250.00',
        ]);
    }
}
