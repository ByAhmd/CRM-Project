<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Contact;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Contacts (D-4, D-6, D-13): permission + visibility scope through ChecksPermissions.
 * A soft-deleted contact accepts no write but restore (D-13).
 */
final class ContactPolicy
{
    use ChecksPermissions;

    protected function permissionGroup(): string
    {
        return Contact::permissionGroup();
    }

    public function assign(User $user, ?Contact $contact = null): bool
    {
        return $this->verb($user, 'assign', $contact);
    }

    public function merge(User $user, ?Contact $contact = null): bool
    {
        return $this->verb($user, 'merge', $contact);
    }

    /** Sending a templated email (D-10): the cross-cutting `email.send` permission plus reach over the record. */
    public function sendEmail(User $user, ?Contact $contact = null): bool
    {
        return ! $this->isTrashed($contact)
            && $user->can(Permission::EmailSend->value)
            && $this->reaches($user, $contact);
    }

    public function export(User $user): bool
    {
        return $user->can($this->permission('export'));
    }

    public function import(User $user): bool
    {
        return $user->can($this->permission('import'));
    }
}
