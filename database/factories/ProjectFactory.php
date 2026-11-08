<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BadgeColor;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
final class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name_ar' => 'مشروع '.$name,
            'name_en' => $name,
            'color' => BadgeColor::Gray,
            'is_active' => true,
            'is_default' => false,
            'sort' => fake()->numberBetween(20, 900),
        ];
    }

    public function default(): self
    {
        return $this->state(static fn (): array => ['is_default' => true, 'color' => BadgeColor::Primary, 'sort' => 10]);
    }

    public function inactive(): self
    {
        return $this->state(static fn (): array => ['is_active' => false]);
    }
}
