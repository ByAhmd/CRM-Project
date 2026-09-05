<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The part a contact plays in a deal (deal_contacts.role).
 */
enum DealContactRole: string implements HasLabel
{
    case DecisionMaker = 'decision_maker';
    case Influencer = 'influencer';
    case Champion = 'champion';
    case EndUser = 'user';
    case Other = 'other';

    public function getLabel(): string
    {
        return __('enums.deal_contact_role.'.$this->value);
    }
}
