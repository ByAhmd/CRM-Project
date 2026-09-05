<?php

declare(strict_types=1);

namespace App\Exceptions\Access;

use RuntimeException;

/**
 * Raised when a record is assigned to a user outside the actor's reach (D-4):
 * a manager may only assign within their team, an admin anywhere.
 */
final class UnassignableUserException extends RuntimeException
{
    public static function make(): self
    {
        return new self(__('assignment.validation.outside_reach'));
    }
}
