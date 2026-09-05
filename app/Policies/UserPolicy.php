<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Access\RoleService;

/**
 * User administration is one permission, users.manage (decision D-3); there is
 * no per-record scope on users. Two guards sit above it:
 * - nobody deletes or disables their own account;
 * - the last active super admin cannot be deleted (demotion is refused by RoleService).
 * Permanent deletion is never offered (D-13).
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::UsersManage->value);
    }

    public function view(User $user, User $target): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ?User $target = null): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, ?User $target = null): bool
    {
        if ($target === null) {
            return $this->viewAny($user);
        }

        if ($target->is($user)) {
            return false;
        }

        if (app(RoleService::class)->isLastActiveSuperAdmin($target)) {
            return false;
        }

        return $this->viewAny($user);
    }

    public function restore(User $user, ?User $target = null): bool
    {
        return $this->viewAny($user);
    }

    public function forceDelete(User $user, ?User $target = null): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return $this->delete($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->restore($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function invite(User $user, User $target): bool
    {
        return $this->update($user, $target);
    }
}
