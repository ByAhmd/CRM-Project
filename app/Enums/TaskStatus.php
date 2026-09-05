<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TaskStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __('enums.task_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::InProgress => 'info',
            self::Completed => 'success',
            self::Cancelled => 'warning',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::InProgress;
    }
}
