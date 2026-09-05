<?php

declare(strict_types=1);

namespace App\Exceptions\Access;

use RuntimeException;

/**
 * Raised when an action would leave the system without any active super admin
 * (decision A-12): demoting, disabling or deleting the last one.
 */
final class LastSuperAdminException extends RuntimeException
{
    public static function make(): self
    {
        return new self(__('users.validation.last_super_admin'));
    }
}
