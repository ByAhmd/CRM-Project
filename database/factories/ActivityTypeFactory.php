<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActivityKind;
use App\Enums\BadgeColor;
use App\Models\ActivityType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityType>
 */
final class ActivityTypeFactory extends Factory
{
    public function definition(): array
    {
        $suffix = fake()->unique()->numberBetween(1, 9999);

        return [
            'name_ar' => 'نوع نشاط '.$suffix,
            'name_en' => 'Activity Type '.$suffix,
            'kind' => ActivityKind::Other,
            'icon' => ActivityKind::Other->getIcon()->name,
            'color' => BadgeColor::Gray,
            'is_system' => false,
            'is_active' => true,
            'sort' => 0,
        ];
    }

    public function system(ActivityKind $kind): static
    {
        return $this->state(fn (): array => [
            'kind' => $kind,
            'icon' => $kind->getIcon()->name,
            'color' => BadgeColor::from($kind->getColor()),
            'is_system' => true,
        ]);
    }
}
