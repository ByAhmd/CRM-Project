<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Models\Deal;
use App\Models\DealProduct;
use App\Models\Product;
use App\Services\Deals\DealAmountCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Deal amounts, probabilities and weighting (decision D-8): line totals honour
 * the discount, the amount follows the lines whenever there are any, a deal
 * without lines keeps its manual amount, and the effective probability is the
 * override or the stage's.
 */
final class DealAmountTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
    }

    #[Test]
    public function a_line_total_honours_the_discount_to_two_decimals(): void
    {
        $deal = Deal::factory()->create();
        $product = Product::factory()->create(['unit_price' => 100]);

        $line = DealProduct::factory()->create([
            'deal_id' => $deal->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => 3,
            'unit_price' => 100,
            'discount_percent' => 12.5,
        ]);

        $this->assertSame('262.50', $line->refresh()->line_total);

        $line->update(['quantity' => 3, 'unit_price' => 33.33, 'discount_percent' => 10]);
        $this->assertSame('89.99', $line->refresh()->line_total);

        $line->update(['discount_percent' => 0]);
        $this->assertSame('99.99', $line->refresh()->line_total);
    }

    #[Test]
    public function the_amount_follows_the_lines_as_they_are_added_changed_and_removed(): void
    {
        $deal = Deal::factory()->create(['amount' => 5000]);
        $product = Product::factory()->create(['unit_price' => 200]);

        $first = DealProduct::factory()->create(['deal_id' => $deal->getKey(), 'product_id' => $product->getKey(), 'unit_price' => 200]);
        $this->assertSame('200.00', $deal->refresh()->amount);

        $second = DealProduct::factory()->create(['deal_id' => $deal->getKey(), 'product_id' => $product->getKey(), 'unit_price' => 300]);
        $this->assertSame('500.00', $deal->refresh()->amount);

        $first->update(['quantity' => 2, 'discount_percent' => 25]);
        $this->assertSame('600.00', $deal->refresh()->amount);

        $second->delete();
        $this->assertSame('300.00', $deal->refresh()->amount);

        // The last line is removed: the deal has no lines any more, so the amount is kept as the manual figure.
        $first->delete();
        $this->assertSame('300.00', $deal->refresh()->amount);
        $this->assertFalse(app(DealAmountCalculator::class)->hasLines($deal));
    }

    #[Test]
    public function a_deal_without_lines_keeps_its_manual_amount(): void
    {
        $deal = Deal::factory()->create(['amount' => 1234.56]);

        $this->assertFalse(app(DealAmountCalculator::class)->hasLines($deal));
        app(DealAmountCalculator::class)->recalculate($deal);

        $this->assertSame('1234.56', $deal->refresh()->amount);

        $deal->update(['amount' => 2000]);
        $this->assertSame('2000.00', $deal->refresh()->amount);
    }

    #[Test]
    public function the_effective_probability_is_the_override_or_the_stage_probability(): void
    {
        $deal = Deal::factory()->create(['probability' => null]);

        $this->assertSame(10, $deal->stage?->probability);
        $this->assertSame(10, $deal->effective_probability);

        $deal->update(['probability' => 55]);
        $this->assertSame(55, $deal->refresh()->effective_probability);

        $deal->update(['probability' => null]);
        $this->assertSame(10, $deal->refresh()->effective_probability);
    }

    #[Test]
    public function the_weighted_amount_is_the_amount_times_the_effective_probability(): void
    {
        $deal = Deal::factory()->create(['amount' => 1000, 'probability' => 30]);
        $this->assertSame('300.00', $deal->weighted_amount);

        $deal->update(['amount' => 999.99, 'probability' => 33]);
        $this->assertSame('330.00', $deal->refresh()->weighted_amount);

        $deal->update(['probability' => null]);
        $this->assertSame('100.00', $deal->refresh()->weighted_amount);

        $deal->update(['probability' => 0]);
        $this->assertSame('0.00', $deal->refresh()->weighted_amount);
    }
}
