<?php

declare(strict_types=1);

namespace App\Exceptions\Access;

use RuntimeException;

/**
 * Raised when someone tries to rename, delete or strip the super_admin role
 * (decision A-12).
 */
final class LockedRoleException extends RuntimeException
{
    public static function make(): self
    {
        return new self(__('roles.validation.locked'));
    }
}
