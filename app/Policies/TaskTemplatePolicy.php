<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TaskTemplate;
use App\Models\User;

/**
 * Routine tasks (decision D-22) belong to the people who hand out work: every
 * ability needs `task.assign` (D-14 — super admins and admins by default, and
 * any role a super admin grants it to); there is no permission key of their
 * own. A routine is configuration, not an owned record, so there is no record
 * scope: whoever may hand out work sees and edits every routine. Pausing and
 * resuming are edits. A routine has no soft deletes — `delete` removes it
 * while the tasks it created stay — so there is nothing to restore, and the
 * force-delete abilities stay refused as on every other policy (D-13).
 */
final class TaskTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::TaskAssign->value);
    }

    public function view(User $user, TaskTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ?TaskTemplate $template = null): bool
    {
        return $this->viewAny($user);
    }

    public function pause(User $user, ?TaskTemplate $template = null): bool
    {
        return $this->update($user, $template);
    }

    public function resume(User $user, ?TaskTemplate $template = null): bool
    {
        return $this->update($user, $template);
    }

    public function delete(User $user, ?TaskTemplate $template = null): bool
    {
        return $this->viewAny($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->delete($user);
    }

    public function restore(User $user, ?TaskTemplate $template = null): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ?TaskTemplate $template = null): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
