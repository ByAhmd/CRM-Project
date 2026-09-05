<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * User account status.
 *
 * A newly invited user is pending until they set a password through the
 * invitation link (D-11: invite-only accounts). A disabled user has been
 * switched off deliberately. Neither is deleted, so the audit trail stays intact.
 */
enum UserStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Pending = 'pending';
    case Disabled = 'disabled';

    public function getLabel(): string
    {
        return __('enums.user_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Pending => 'warning',
            self::Disabled => 'gray',
        };
    }

    /** Only an active user may sign in. */
    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static fn (array $carry, self $case): array => $carry + [$case->value => $case->getLabel()],
            [],
        );
    }
}
