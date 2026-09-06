<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\EmailTemplate;
use App\Models\User;

/**
 * Email templates are settings data with their own permission keys (decision
 * D-10): every role that may send mail reads the templates to pick one,
 * while creating, editing and deleting them is separate. Templates are not
 * owned records, so there is no visibility scope. Permanent deletion is
 * never offered (D-13); restore follows delete.
 */
final class EmailTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::EmailTemplateViewAny->value);
    }

    public function view(User $user, EmailTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::EmailTemplateCreate->value);
    }

    public function update(User $user, ?EmailTemplate $template = null): bool
    {
        return $user->can(Permission::EmailTemplateUpdate->value);
    }

    public function delete(User $user, ?EmailTemplate $template = null): bool
    {
        return $user->can(Permission::EmailTemplateDelete->value);
    }

    public function restore(User $user, ?EmailTemplate $template = null): bool
    {
        return $this->delete($user, $template);
    }

    public function forceDelete(User $user, ?EmailTemplate $template = null): bool
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
