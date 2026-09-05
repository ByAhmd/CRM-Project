<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LeadSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadSource>
 */
final class LeadSourceFactory extends Factory
{
    public function definition(): array
    {
        $suffix = fake()->unique()->numberBetween(1, 9999);

        return [
            'name_ar' => 'مصدر '.$suffix,
            'name_en' => 'Source '.$suffix,
            'is_active' => true,
            'sort' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
