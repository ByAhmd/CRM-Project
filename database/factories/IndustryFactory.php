<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Industry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Industry>
 */
final class IndustryFactory extends Factory
{
    public function definition(): array
    {
        $suffix = fake()->unique()->numberBetween(1, 9999);

        return [
            'name_ar' => 'قطاع '.$suffix,
            'name_en' => 'Industry '.$suffix,
            'is_active' => true,
            'sort' => 0,
        ];
    }
}
