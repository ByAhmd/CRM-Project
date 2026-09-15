<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Schema;

use App\Enums\LeadScoringRuleKind;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\LeadScoringRules\Pages\CreateLeadScoringRule;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadScoringRule;
use App\Models\LeadSource;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Probe item 6 and the column sizes of section 3: values the forms accept
 * must fit the columns they land in, and the unique index the design lists
 * on lead_scoring_rules must actually refuse a duplicate rule.
 */
final class ColumnBoundsAndUniquenessProbeTest extends TestCase
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
    public function a_long_international_phone_the_lead_form_accepts_fits_phone_normalized(): void
    {
        $admin = $this->admin();
        $phone = '+'.str_repeat('9', 25); // 26 characters: inside the form's maxLength(30)

        try {
            $component = Livewire::actingAs($admin)
                ->test(CreateLead::class)
                ->fillForm(['first_name' => 'Long', 'last_name' => 'Phone', 'phone' => $phone])
                ->call('create');
        } catch (Throwable $exception) {
            $this->fail('A phone the form accepts overflowed phone_normalized VARCHAR(20): '.$exception::class.': '.$exception->getMessage());
        }

        // Either the lead is stored, or the form refuses the phone with a field error.
        $this->assertTrue(Lead::query()->where('last_name', 'Phone')->exists() || $component->errors()->has('data.phone'));
    }

    #[Test]
    public function a_manual_deal_amount_beyond_decimal_14_2_is_a_validation_error_not_a_crash(): void
    {
        $admin = $this->admin();

        try {
            Livewire::actingAs($admin)
                ->test(CreateDeal::class)
                ->fillForm(['title' => 'Huge deal', 'amount' => '10000000000000'])
                ->call('create')
                ->assertHasFormErrors(['amount']);
        } catch (Throwable $exception) {
            $this->fail('An amount beyond DECIMAL(14,2) crashed the create page: '.$exception::class.': '.$exception->getMessage());
        }

        $this->assertFalse(Deal::query()->where('title', 'Huge deal')->exists());
    }

    #[Test]
    public function a_line_whose_quantity_or_total_exceeds_its_columns_is_a_validation_error_not_a_crash(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create(['name_en' => 'Bulk item', 'name_ar' => 'صنف بالجملة', 'unit_price' => 1000]);

        try {
            Livewire::actingAs($admin)
                ->test(CreateDeal::class)
                ->fillForm([
                    'title' => 'Bulk deal',
                    'products' => [
                        ['product_id' => $product->getKey(), 'description' => 'Bulk', 'quantity' => '100000000000', 'unit_price' => '1000', 'discount_percent' => 0],
                    ],
                ])
                ->call('create')
                ->assertHasFormErrors();
        } catch (Throwable $exception) {
            $this->fail('A line beyond DECIMAL(12,2)/DECIMAL(14,2) crashed the create page: '.$exception::class.': '.$exception->getMessage());
        }

        $this->assertFalse(Deal::query()->where('title', 'Bulk deal')->exists());
    }

    #[Test]
    public function the_database_refuses_a_duplicate_scoring_rule(): void
    {
        $source = LeadSource::query()->where('name_en', 'Website')->firstOrFail();
        $row = [
            'kind' => LeadScoringRuleKind::Source->value,
            'reference_id' => $source->getKey(),
            'field' => null,
            'within_days' => null,
            'points' => 10,
            'is_active' => true,
            'sort' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('lead_scoring_rules')->insert($row);

        $this->expectException(QueryException::class);

        DB::table('lead_scoring_rules')->insert($row);
    }

    #[Test]
    public function deleting_a_lead_source_never_leaves_a_scoring_rule_pointing_at_nothing(): void
    {
        $source = LeadSource::factory()->create(['name_en' => 'Fair', 'name_ar' => 'معرض']);
        LeadScoringRule::factory()->create([
            'kind' => LeadScoringRuleKind::Source,
            'reference_id' => $source->getKey(),
            'points' => 10,
        ]);

        try {
            $source->delete();
        } catch (Throwable) {
            // Refusing the delete is an acceptable answer too.
        }

        $dangling = LeadScoringRule::query()
            ->where('kind', LeadScoringRuleKind::Source->value)
            ->whereNotIn('reference_id', LeadSource::query()->select('id'))
            ->count();

        $this->assertSame(0, $dangling, 'lead_scoring_rules.reference_id has no foreign key, so the rule now references a deleted source.');
    }

    #[Test]
    public function the_form_refuses_a_second_rule_for_the_same_source(): void
    {
        $admin = $this->admin();
        $source = LeadSource::query()->where('name_en', 'Website')->firstOrFail();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            Livewire::actingAs($admin)
                ->test(CreateLeadScoringRule::class)
                ->fillForm(['kind' => LeadScoringRuleKind::Source->value, 'reference_id' => $source->getKey(), 'points' => 15])
                ->call('create');
        }

        $this->assertSame(1, LeadScoringRule::query()->where('kind', LeadScoringRuleKind::Source->value)->where('reference_id', $source->getKey())->count());
    }
}
