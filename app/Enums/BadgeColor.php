<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The colours an administrator may give a configurable lookup (status, stage,
 * activity type, tag). Values are Filament colour names, so a badge or a
 * kanban column renders with the panel's own palette in both themes.
 */
enum BadgeColor: string implements HasLabel
{
    case Primary = 'primary';
    case Gray = 'gray';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
    case Info = 'info';

    public function getLabel(): string
    {
        return __('enums.badge_color.'.$this->value);
    }
}
