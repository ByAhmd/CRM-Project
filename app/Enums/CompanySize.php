<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Head-count band of an account.
 */
enum CompanySize: string implements HasLabel
{
    case From1To10 = '1_10';
    case From11To50 = '11_50';
    case From51To200 = '51_200';
    case From201To500 = '201_500';
    case From501To1000 = '501_1000';
    case Above1000 = '1000_plus';

    public function getLabel(): string
    {
        return __('enums.company_size.'.$this->value);
    }
}
