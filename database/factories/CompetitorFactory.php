<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Competitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Competitor>
 */
final class CompetitorFactory extends Factory
{
    public function definition(): array
    {
        $suffix = fake()->unique()->numberBetween(1, 9999);

        return [
            'name' => 'Competitor '.$suffix,
            'website' => 'https://competitor-'.$suffix.'.example.com',
            'notes' => null,
            'is_active' => true,
        ];
    }
}
