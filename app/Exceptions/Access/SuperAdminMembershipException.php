<?php

declare(strict_types=1);

namespace App\Exceptions\Access;

use RuntimeException;

/**
 * Raised when someone without `roles.manage` grants, removes or changes a
 * super_admin (decision A-12): super admin membership and super admin
 * accounts are administered by super admins only, so `users.manage` can never
 * be turned into role administration.
 */
final class SuperAdminMembershipException extends RuntimeException
{
    public static function make(): self
    {
        return new self(__('users.validation.super_admin_membership'));
    }
}
