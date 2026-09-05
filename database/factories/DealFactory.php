<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ForecastCategory;
use App\Models\Account;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\Settings\PipelineService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open deal in the default pipeline's default stage (DealObserver derives
 * the Open status). Closing goes through DealStageWorkflow in tests, so no
 * won()/lost() states are provided.
 *
 * @extends Factory<Deal>
 */
final class DealFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->company().' – '.fake()->word(),
            'account_id' => Account::factory(),
            'pipeline_id' => fn (): int => $this->defaultPipelineId(),
            'stage_id' => fn (array $attributes): int => (int) PipelineStage::query()
                ->where('pipeline_id', $attributes['pipeline_id'])
                ->where('is_default', true)
                ->value('id'),
            'amount' => fake()->randomFloat(2, 1000, 100000),
            'currency' => app(SettingsRepository::class)->currency(),
            'forecast_category' => ForecastCategory::Pipeline,
            'expected_close_date' => now()->addDays(30)->toDateString(),
        ];
    }

    private function defaultPipelineId(): int
    {
        $id = Pipeline::query()->where('is_default', true)->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return (int) app(PipelineService::class)->create([
            'name_ar' => 'المبيعات',
            'name_en' => 'Sales',
            'is_default' => true,
            'is_active' => true,
        ])->getKey();
    }
}
