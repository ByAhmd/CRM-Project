<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    public function definition(): array
    {
        $suffix = fake()->unique()->numberBetween(1, 9999);

        return [
            'code' => sprintf('PRD-%04d', $suffix),
            'name_ar' => 'منتج '.$suffix,
            'name_en' => 'Product '.$suffix,
            'unit_price' => fake()->randomFloat(2, 10, 5000),
            'is_active' => true,
        ];
    }
}
