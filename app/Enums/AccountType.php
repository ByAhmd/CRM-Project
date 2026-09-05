<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle type of an account (decision D-6): companies and customers are one
 * entity; a prospect becomes a customer on its first won deal.
 */
enum AccountType: string implements HasColor, HasLabel
{
    case Prospect = 'prospect';
    case Customer = 'customer';
    case Partner = 'partner';
    case Other = 'other';

    public function getLabel(): string
    {
        return __('enums.account_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Prospect => 'info',
            self::Customer => 'success',
            self::Partner => 'primary',
            self::Other => 'gray',
        };
    }
}
