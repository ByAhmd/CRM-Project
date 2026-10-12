<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Whose tasks «عملي» (My Work) shows (decision D-27, amended 2026-10-12): the
 * reader's own, the ones they handed out to others («أسندتُها» — ClickUp's
 * "Delegated"), or everyone's inside their visibility scope («الفريق»). The
 * first build showed only the reader's own, so an administrator who hands out
 * work saw an empty home (the owner's report, 2026-10-12). Which views a
 * reader is offered, and which one opens first, is MyWorkFeed's rule.
 */
enum MyWorkView: string implements HasIcon, HasLabel
{
    case Mine = 'mine';
    case Delegated = 'delegated';
    case Team = 'team';

    public function getLabel(): string
    {
        return __('enums.my_work_view.'.$this->value);
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Mine => Heroicon::OutlinedUser,
            self::Delegated => Heroicon::OutlinedPaperAirplane,
            self::Team => Heroicon::OutlinedUserGroup,
        };
    }
}
