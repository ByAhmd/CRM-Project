<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
final class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            // The default project unless the test says otherwise (D-30); the migration created it.
            'project_id' => fn (): int => (int) (Project::query()->where('is_default', true)->value('id')
                ?? Project::factory()->default()->create()->getKey()),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'job_title' => fake()->jobTitle(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => '05'.fake()->numerify('########'),
            'preferred_locale' => 'ar',
            'is_primary' => false,
            'country' => 'SA',
        ];
    }
}
