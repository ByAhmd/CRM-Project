<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Accounts (D-4, D-6, D-13): permission + visibility scope through ChecksPermissions.
 */
final class AccountPolicy
{
    use ChecksPermissions;

    protected function permissionGroup(): string
    {
        return Account::permissionGroup();
    }

    public function assign(User $user, ?Account $account = null): bool
    {
        return $this->verb($user, 'assign', $account);
    }

    public function merge(User $user, ?Account $account = null): bool
    {
        return $this->verb($user, 'merge', $account);
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
