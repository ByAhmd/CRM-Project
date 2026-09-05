<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * What kind of to-do a task is; meetings and calls carry a time span and appear on the calendar.
 */
enum TaskKind: string implements HasIcon, HasLabel
{
    case Task = 'task';
    case FollowUp = 'follow_up';
    case Call = 'call';
    case Meeting = 'meeting';

    public function getLabel(): string
    {
        return __('enums.task_kind.'.$this->value);
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Task => Heroicon::OutlinedCheckCircle,
            self::FollowUp => Heroicon::OutlinedArrowPath,
            self::Call => Heroicon::OutlinedPhone,
            self::Meeting => Heroicon::OutlinedCalendarDays,
        };
    }
}
