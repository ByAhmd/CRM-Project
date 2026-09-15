<?php

declare(strict_types=1);

namespace App\Exceptions\Access;

use RuntimeException;

/**
 * Raised when a user tries to switch off their own account (docs/PERMISSIONS.md,
 * "Guards above the permissions"): nobody disables their own account or sets
 * it back to pending.
 */
final class SelfStatusChangeException extends RuntimeException
{
    public static function make(): self
    {
        return new self(__('users.validation.self_status'));
    }
}
