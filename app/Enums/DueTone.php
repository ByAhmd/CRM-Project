<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a task's due date reads at a glance (decision D-27), on the
 * organisation calendar: an open task due before today is overdue (red),
 * one due today — at any hour, passed or not, exactly as the dashboard
 * counts it — is due today (amber), a later one is upcoming (no colour), and
 * a closed task's date is history (muted), never a warning.
 *
 * A code enum for behaviour only: the words are RelativeDue's text.
 */
enum DueTone: string
{
    case Overdue = 'overdue';
    case Today = 'today';
    case Upcoming = 'upcoming';
    case Closed = 'closed';

    /** The Filament colour a column or an entry gives the date. */
    public function color(): ?string
    {
        return match ($this) {
            self::Overdue => 'danger',
            self::Today => 'warning',
            self::Upcoming => null,
            self::Closed => 'gray',
        };
    }

    /** The theme.css modifier of `.crm-due`. */
    public function cssClass(): string
    {
        return 'crm-due--'.$this->value;
    }
}
