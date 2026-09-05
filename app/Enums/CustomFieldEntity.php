<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The four entities that carry custom fields (decision D-9).
 */
enum CustomFieldEntity: string implements HasLabel
{
    case Lead = 'lead';
    case Contact = 'contact';
    case Account = 'account';
    case Deal = 'deal';

    public function getLabel(): string
    {
        return __('enums.custom_field_entity.'.$this->value);
    }
}
