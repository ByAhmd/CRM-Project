<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CloseReasonKind;
use App\Models\DealCloseReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealCloseReason>
 */
final class DealCloseReasonFactory extends Factory
{
    public function definition(): array
    {
        $suffix = fake()->unique()->numberBetween(1, 9999);

        return [
            'kind' => CloseReasonKind::Lost,
            'name_ar' => 'سبب الإغلاق '.$suffix,
            'name_en' => 'Close Reason '.$suffix,
            'is_active' => true,
            'sort' => 0,
        ];
    }

    public function won(): static
    {
        return $this->state(fn (): array => ['kind' => CloseReasonKind::Won]);
    }

    public function lost(): static
    {
        return $this->state(fn (): array => ['kind' => CloseReasonKind::Lost]);
    }
}
