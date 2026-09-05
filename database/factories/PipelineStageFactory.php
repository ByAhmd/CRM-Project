<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BadgeColor;
use App\Enums\StageKind;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineStage>
 */
final class PipelineStageFactory extends Factory
{
    public function definition(): array
    {
        $suffix = fake()->unique()->numberBetween(1, 9999);

        return [
            'pipeline_id' => Pipeline::factory(),
            'name_ar' => 'مرحلة '.$suffix,
            'name_en' => 'Stage '.$suffix,
            'kind' => StageKind::Open,
            'probability' => 10,
            'color' => BadgeColor::Primary,
            'is_default' => false,
            'sort' => 0,
        ];
    }

    public function won(): self
    {
        return $this->state(fn (): array => [
            'kind' => StageKind::Won,
            'probability' => 100,
            'color' => BadgeColor::Success,
        ]);
    }

    public function lost(): self
    {
        return $this->state(fn (): array => [
            'kind' => StageKind::Lost,
            'probability' => 0,
            'color' => BadgeColor::Danger,
        ]);
    }

    public function asDefault(): self
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
