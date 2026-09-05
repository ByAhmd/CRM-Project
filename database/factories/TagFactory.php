<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BadgeColor;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
final class TagFactory extends Factory
{
    public function definition(): array
    {
        $suffix = fake()->unique()->numberBetween(1, 9999);

        return [
            'name_ar' => 'وسم '.$suffix,
            'name_en' => 'Tag '.$suffix,
            'color' => BadgeColor::Gray,
            'is_active' => true,
        ];
    }
}
