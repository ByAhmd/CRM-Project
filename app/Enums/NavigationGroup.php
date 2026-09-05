<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Sidebar groups. Case order IS the sidebar order — do not sort.
 *
 * The case is the identity and the translated label is only its presentation.
 * Filament matches a Resource to its group by value; if both sides supplied a
 * translated string the match would break the moment a user switched locale
 * (the panel is configured once at boot, Resources render per request).
 */
enum NavigationGroup: string implements HasLabel
{
    case Sales = 'sales';
    case Contacts = 'contacts';
    case Activities = 'activities';
    case Reports = 'reports';
    case Settings = 'settings';
    case System = 'system';

    public function getLabel(): string
    {
        return __('navigation.'.$this->value);
    }
}
