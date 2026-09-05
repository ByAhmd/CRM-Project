<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum LeadPriority: string implements HasColor, HasLabel
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function getLabel(): string
    {
        return __('enums.lead_priority.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Medium => 'warning',
            self::High => 'danger',
        };
    }
}
