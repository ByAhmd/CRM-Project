<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Behaviour of a configurable activity type (decision A-4).
 *
 * Administrators create and name activity types; the kind decides how the
 * timeline renders them and which fields apply (direction for calls and
 * emails, duration for calls and meetings). One system type per kind is
 * seeded and cannot be deleted.
 */
enum ActivityKind: string implements HasColor, HasIcon, HasLabel
{
    case Call = 'call';
    case Meeting = 'meeting';
    case Email = 'email';
    case Note = 'note';
    case Task = 'task';
    case System = 'system';
    case Other = 'other';

    public function getLabel(): string
    {
        return __('enums.activity_kind.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Call => 'info',
            self::Meeting => 'primary',
            self::Email => 'warning',
            self::Note => 'gray',
            self::Task => 'success',
            self::System => 'gray',
            self::Other => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Call => Heroicon::OutlinedPhone,
            self::Meeting => Heroicon::OutlinedCalendarDays,
            self::Email => Heroicon::OutlinedEnvelope,
            self::Note => Heroicon::OutlinedDocumentText,
            self::Task => Heroicon::OutlinedCheckCircle,
            self::System => Heroicon::OutlinedCog6Tooth,
            self::Other => Heroicon::OutlinedClipboardDocumentList,
        };
    }

    public function hasDirection(): bool
    {
        return $this === self::Call || $this === self::Email;
    }

    public function hasDuration(): bool
    {
        return $this === self::Call || $this === self::Meeting;
    }

    /** Kinds users may pick when logging an activity; System is written by the application. */
    public function isUserSelectable(): bool
    {
        return $this !== self::System;
    }
}
