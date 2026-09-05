<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Team;
use App\Models\User;

/**
 * Teams are configuration (decision D-4): one permission, teams.manage.
 * Permanent deletion is never offered (D-13).
 */
final class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::TeamsManage->value);
    }

    public function view(User $user, Team $team): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ?Team $team = null): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, ?Team $team = null): bool
    {
        return $this->viewAny($user);
    }

    public function restore(User $user, ?Team $team = null): bool
    {
        return $this->viewAny($user);
    }

    public function forceDelete(User $user, ?Team $team = null): bool
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
}
