<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Forecast bucket of an open deal (decision D-8). The forecast report groups
 * weighted amounts by month and category.
 */
enum ForecastCategory: string implements HasColor, HasLabel
{
    case Pipeline = 'pipeline';
    case BestCase = 'best_case';
    case Commit = 'commit';
    case Omitted = 'omitted';

    public function getLabel(): string
    {
        return __('enums.forecast_category.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pipeline => 'gray',
            self::BestCase => 'info',
            self::Commit => 'success',
            self::Omitted => 'warning',
        };
    }

    public function countsTowardsForecast(): bool
    {
        return $this !== self::Omitted;
    }
}
