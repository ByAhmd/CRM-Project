<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\SavedView;
use App\Models\User;

/**
 * Saved views (decision A-8): every signed-in user may keep their own; a view
 * is readable by its owner or, once shared, by everyone; only the owner
 * changes it; deleting is the owner's, plus a `users.manage` holder for the
 * shared views of others (an administrator tidying up after a leaver);
 * sharing itself is the cross-cutting `saved_view.share` permission.
 *
 * Saved views are hard-deleted one at a time from the views menu; there is
 * no bulk surface and nothing to restore. The bulk and permanent-deletion
 * abilities are still defined — and refused — because Filament's
 * authorisation helper allows an ability a policy does not define.
 */
final class SavedViewPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SavedView $view): bool
    {
        return $view->isOwnedBy($user) || $view->is_shared;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SavedView $view): bool
    {
        return $view->isOwnedBy($user);
    }

    public function delete(User $user, SavedView $view): bool
    {
        if ($view->isOwnedBy($user)) {
            return true;
        }

        return $view->is_shared && $user->can(Permission::UsersManage->value);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, SavedView $view): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function share(User $user): bool
    {
        return $user->can(Permission::SavedViewShare->value);
    }
}
