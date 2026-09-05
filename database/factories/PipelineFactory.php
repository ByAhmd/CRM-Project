<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Pipeline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pipeline>
 */
final class PipelineFactory extends Factory
{
    public function definition(): array
    {
        $suffix = fake()->unique()->numberBetween(1, 9999);

        return [
            'name_ar' => 'مسار المبيعات '.$suffix,
            'name_en' => 'Sales Pipeline '.$suffix,
            'is_default' => false,
            'is_active' => true,
            'sort' => 0,
        ];
    }

    public function asDefault(): self
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
