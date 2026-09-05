<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Role administration (decision D-3): roles.manage, held by super admins only
 * in the seeded matrix. super_admin itself is locked; seeded roles keep their
 * key but stay editable.
 */
final class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RolesManage->value);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ?Role $role = null): bool
    {
        if ($role?->isLocked() === true) {
            return false;
        }

        return $this->viewAny($user);
    }

    public function delete(User $user, ?Role $role = null): bool
    {
        if ($role !== null && ($role->isLocked() || $role->isSeeded())) {
            return false;
        }

        return $this->viewAny($user);
    }

    public function restore(User $user, ?Role $role = null): bool
    {
        return false;
    }

    public function forceDelete(User $user, ?Role $role = null): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
