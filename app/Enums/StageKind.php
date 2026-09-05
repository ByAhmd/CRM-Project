<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What a pipeline stage means to the deal workflow (decision D-8).
 * Every pipeline has at least one Open stage, exactly one Won and exactly one Lost.
 */
enum StageKind: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function getLabel(): string
    {
        return __('enums.stage_kind.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'primary',
            self::Won => 'success',
            self::Lost => 'danger',
        };
    }

    public function isClosed(): bool
    {
        return $this !== self::Open;
    }
}
