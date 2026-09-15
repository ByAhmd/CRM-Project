<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Access\RoleService;

/**
 * User administration is one permission, users.manage (decision D-3); there is
 * no per-record scope on users. Guards sit above it:
 * - a super admin account is edited, invited, deleted or restored only by a
 *   holder of roles.manage (A-12: admin = everything except role
 *   administration, so users.manage never reaches a super admin);
 * - nobody deletes or disables their own account;
 * - the last active super admin cannot be deleted (demotion and switching off
 *   are refused by RoleService).
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
        if (! $this->viewAny($user)) {
            return false;
        }

        return $target === null || $this->administers($user, $target);
    }

    public function delete(User $user, ?User $target = null): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($target === null) {
            return true;
        }

        if ($target->is($user)) {
            return false;
        }

        if (! $this->administers($user, $target)) {
            return false;
        }

        return ! app(RoleService::class)->isLastActiveSuperAdmin($target);
    }

    public function restore(User $user, ?User $target = null): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return $target === null || $this->administers($user, $target);
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

    /** Any target that is not a super admin; a super admin only for roles.manage holders. */
    private function administers(User $user, User $target): bool
    {
        return ! $target->isSuperAdmin() || app(RoleService::class)->mayAdministerSuperAdmins($user);
    }
}
