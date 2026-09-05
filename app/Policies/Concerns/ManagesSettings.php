<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Policy body for configurable lookups (decision A-4): one permission,
 * settings.manage, covers viewing and editing; permanent deletion is never
 * granted (D-13). Models that need extra guards (system rows, singletons)
 * override the specific method and call the trait's implementation.
 */
trait ManagesSettings
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ?Model $record = null): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, ?Model $record = null): bool
    {
        return $this->viewAny($user);
    }

    public function restore(User $user, ?Model $record = null): bool
    {
        return $this->viewAny($user);
    }

    public function forceDelete(User $user, ?Model $record = null): bool
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

    public function reorder(User $user): bool
    {
        return $this->update($user);
    }
}
