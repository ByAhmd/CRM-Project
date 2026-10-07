<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * The due-date chips of the Tasks workspace (decision D-27): quick add
 * offers all five, the reschedule chips every one but "no date" — a task
 * already due is moved, never cleared, from a chip. What moment each chip
 * means is DueShortcuts' rule, on the organisation calendar.
 */
enum DueShortcut: string implements HasIcon, HasLabel
{
    case Today = 'today';
    case Tomorrow = 'tomorrow';
    case NextWeek = 'next_week';
    case Pick = 'pick';
    case None = 'none';

    public function getLabel(): string
    {
        return __('enums.due_shortcut.'.$this->value);
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Today => Heroicon::OutlinedSun,
            self::Tomorrow => Heroicon::OutlinedCalendar,
            self::NextWeek => Heroicon::OutlinedCalendarDays,
            self::Pick => Heroicon::OutlinedPencilSquare,
            self::None => Heroicon::OutlinedMinusCircle,
        };
    }

    /**
     * The chips that move an existing due date: a fixed day or a picked one.
     *
     * @return list<self>
     */
    public static function rescheduleChoices(): array
    {
        return [self::Today, self::Tomorrow, self::NextWeek, self::Pick];
    }
}
