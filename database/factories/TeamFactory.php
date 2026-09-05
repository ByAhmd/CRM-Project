<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
final class TeamFactory extends Factory
{
    public function definition(): array
    {
        $suffix = fake()->unique()->numberBetween(1, 9999);

        return [
            'name_ar' => 'فريق المبيعات '.$suffix,
            'name_en' => 'Sales Team '.$suffix,
            'is_active' => true,
            'sort' => 0,
        ];
    }
}
