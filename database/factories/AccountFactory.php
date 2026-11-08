<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\CompanySize;
use App\Models\Account;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
final class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            // The default project unless the test says otherwise (D-30); the migration created it.
            'project_id' => fn (): int => (int) (Project::query()->where('is_default', true)->value('id')
                ?? Project::factory()->default()->create()->getKey()),
            'name' => fake()->company(),
            'type' => AccountType::Prospect,
            'size' => fake()->randomElement(CompanySize::cases()),
            'website' => 'https://'.fake()->domainName(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => '011'.fake()->numerify('#######'),
            'city' => fake()->randomElement(['الرياض', 'جدة', 'الدمام', 'مكة المكرمة']),
            'country' => 'SA',
        ];
    }

    public function customer(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => AccountType::Customer,
            'customer_since' => now()->toDateString(),
        ]);
    }
}
