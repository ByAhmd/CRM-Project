<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ActivityLog;
use App\Models\User;

/**
 * The audit ledger is read-only (decision A-5): audit.view grants the screen,
 * and no ability can ever change or remove a row.
 */
final class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::AuditView->value);
    }

    public function view(User $user, ActivityLog $log): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ?ActivityLog $log = null): bool
    {
        return false;
    }

    public function delete(User $user, ?ActivityLog $log = null): bool
    {
        return false;
    }

    public function restore(User $user, ?ActivityLog $log = null): bool
    {
        return false;
    }

    public function forceDelete(User $user, ?ActivityLog $log = null): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
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
