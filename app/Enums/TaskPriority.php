<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TaskPriority: string implements HasColor, HasLabel
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function getLabel(): string
    {
        return __('enums.task_priority.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Medium => 'info',
            self::High => 'warning',
            self::Urgent => 'danger',
        };
    }
}
