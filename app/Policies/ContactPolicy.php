<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Contacts (D-4, D-6, D-13): permission + visibility scope through ChecksPermissions.
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

    public function export(User $user): bool
    {
        return $user->can($this->permission('export'));
    }

    public function import(User $user): bool
    {
        return $user->can($this->permission('import'));
    }
}
