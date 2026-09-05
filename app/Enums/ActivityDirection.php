<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Direction of a call or email activity.
 */
enum ActivityDirection: string implements HasLabel
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function getLabel(): string
    {
        return __('enums.activity_direction.'.$this->value);
    }
}
