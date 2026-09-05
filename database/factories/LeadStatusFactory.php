<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BadgeColor;
use App\Enums\LeadStatusKind;
use App\Models\LeadStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadStatus>
 */
final class LeadStatusFactory extends Factory
{
    public function definition(): array
    {
        $suffix = fake()->unique()->numberBetween(1, 9999);

        return [
            'name_ar' => 'حالة '.$suffix,
            'name_en' => 'Status '.$suffix,
            'kind' => LeadStatusKind::Working,
            'color' => BadgeColor::Gray,
            'is_default' => false,
            'is_active' => true,
            'sort' => 0,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => LeadStatusKind::New,
            'is_default' => true,
        ]);
    }

    public function converted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => LeadStatusKind::Converted,
            'color' => BadgeColor::Warning,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
