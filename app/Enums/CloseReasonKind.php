<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether a configurable deal close reason explains a win or a loss (decision D-8).
 */
enum CloseReasonKind: string implements HasColor, HasLabel
{
    case Won = 'won';
    case Lost = 'lost';

    public function getLabel(): string
    {
        return __('enums.close_reason_kind.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Won => 'success',
            self::Lost => 'danger',
        };
    }
}
