<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeadPriority;
use App\Models\Lead;
use App\Models\LeadStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
final class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'company_name' => fake()->company(),
            'job_title' => fake()->jobTitle(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '05'.fake()->numerify('########'),
            'city' => fake()->randomElement(['الرياض', 'جدة', 'الدمام']),
            'country' => 'SA',
            'lead_status_id' => fn (): int => (int) (LeadStatus::query()->where('is_default', true)->value('id')
                ?? LeadStatus::factory()->create(['is_default' => true])->getKey()),
            'priority' => LeadPriority::Medium,
        ];
    }
}
