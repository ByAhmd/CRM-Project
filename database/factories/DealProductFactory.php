<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Deal;
use App\Models\DealProduct;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One line of one unit at the product's catalogue price, no discount.
 *
 * @extends Factory<DealProduct>
 */
final class DealProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'product_id' => Product::factory(),
            'quantity' => 1,
            'unit_price' => fn (array $attributes): string => (string) (Product::query()
                ->whereKey($attributes['product_id'])
                ->value('unit_price') ?? '0.00'),
            'discount_percent' => 0,
            'sort' => 0,
        ];
    }
}
