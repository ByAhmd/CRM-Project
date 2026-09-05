<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What a configurable lead status MEANS to the workflow (decisions D-7, A-4).
 *
 * Statuses are rows the administrator names and orders; the kind is the
 * behaviour code reasons about: qualification stamps qualified_at, conversion
 * is allowed only from Qualified, Converted is terminal, Unqualified is
 * terminal but reopenable.
 */
enum LeadStatusKind: string implements HasColor, HasLabel
{
    case New = 'new';
    case Working = 'working';
    case Qualified = 'qualified';
    case Unqualified = 'unqualified';
    case Converted = 'converted';

    public function getLabel(): string
    {
        return __('enums.lead_status_kind.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Working => 'primary',
            self::Qualified => 'success',
            self::Unqualified => 'gray',
            self::Converted => 'warning',
        };
    }

    /** Terminal kinds end the lead's open life; Unqualified may be reopened, Converted may not. */
    public function isTerminal(): bool
    {
        return $this === self::Unqualified || $this === self::Converted;
    }

    /** Exactly one status of this kind must exist (D-7). */
    public function isSingleton(): bool
    {
        return $this === self::Converted;
    }
}
