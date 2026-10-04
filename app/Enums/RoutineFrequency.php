<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How often a routine task runs (decision D-22): weekly on a chosen day
 * (Sunday … Saturday) or monthly on a chosen day 1–28, so a monthly routine
 * has its day in every month. The per-task "repeat after completion" of a
 * one-off series (A-10) is RecurrenceFrequency, not this.
 */
enum RoutineFrequency: string implements HasLabel
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function getLabel(): string
    {
        return __('enums.routine_frequency.'.$this->value);
    }
}
