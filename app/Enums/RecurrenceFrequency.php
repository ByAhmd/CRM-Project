<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Recurring tasks (decision A-10): daily, weekly or monthly, every N units.
 */
enum RecurrenceFrequency: string implements HasLabel
{
    case None = 'none';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function getLabel(): string
    {
        return __('enums.recurrence_frequency.'.$this->value);
    }

    public function repeats(): bool
    {
        return $this !== self::None;
    }
}
