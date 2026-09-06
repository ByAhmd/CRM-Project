<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SavedView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A private, non-default view on the leads list owned by a fresh user; tests
 * set the state columns and the owner explicitly.
 *
 * @extends Factory<SavedView>
 */
final class SavedViewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'resource' => 'leads',
            'name' => 'View '.fake()->unique()->numberBetween(1, 9999),
            'filters' => null,
            'sort_column' => null,
            'sort_direction' => null,
            'search' => null,
            'columns' => null,
            'is_shared' => false,
            'is_default' => false,
        ];
    }

    public function shared(): static
    {
        return $this->state(fn (): array => ['is_shared' => true]);
    }

    public function asDefault(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
