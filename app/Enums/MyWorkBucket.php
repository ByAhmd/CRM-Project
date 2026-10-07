<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * The buckets of «عملي» (My Work), the Tasks home (decision D-27), in the
 * order the page shows them: the reader's open tasks by when they are due on
 * the organisation calendar — overdue (before today), today, the next seven
 * days (tomorrow to a week from today), later, no date — then what they
 * completed in the last seven days. Which task falls in which bucket is
 * MyWorkFeed's rule; this enum names them.
 */
enum MyWorkBucket: string implements HasColor, HasIcon, HasLabel
{
    case Overdue = 'overdue';
    case Today = 'today';
    case NextSevenDays = 'next_7_days';
    case Later = 'later';
    case NoDate = 'no_date';
    case CompletedRecently = 'completed_recently';

    public function getLabel(): string
    {
        return __('enums.my_work_bucket.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Overdue => 'danger',
            self::Today => 'warning',
            self::CompletedRecently => 'success',
            self::NextSevenDays, self::Later, self::NoDate => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Overdue => Heroicon::OutlinedExclamationTriangle,
            self::Today => Heroicon::OutlinedSun,
            self::NextSevenDays => Heroicon::OutlinedCalendarDays,
            self::Later => Heroicon::OutlinedCalendar,
            self::NoDate => Heroicon::OutlinedMinusCircle,
            self::CompletedRecently => Heroicon::OutlinedCheckCircle,
        };
    }

    /**
     * Whether the bucket holds open work — every bucket but the recently
     * completed one, which the page shows folded: it is a record of what
     * was done, not a call to act.
     */
    public function holdsOpenWork(): bool
    {
        return $this !== self::CompletedRecently;
    }
}
