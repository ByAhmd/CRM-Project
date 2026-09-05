<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far a user's view of an owned entity reaches (decision D-4).
 *
 * Resolved by RecordVisibilityResolver from the user's permissions; never
 * stored on the user.
 */
enum VisibilityLevel: string
{
    case None = 'none';
    case Own = 'own';
    case Team = 'team';
    case All = 'all';

    public function label(): string
    {
        return __('enums.visibility_level.'.$this->value);
    }

    public function reachesTeam(): bool
    {
        return $this === self::Team || $this === self::All;
    }
}
