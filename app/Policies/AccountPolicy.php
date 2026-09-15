<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Accounts (D-4, D-6, D-13): permission + visibility scope through ChecksPermissions.
 * The lifecycle type is set by hand only with `account.set_type` (D-6).
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

    /**
     * Setting the lifecycle type (and customer_since) by hand (D-6). A
     * prospect otherwise becomes a customer only through its first won deal;
     * a soft-deleted account is frozen until restored (D-13).
     */
    public function setType(User $user, ?Account $account = null): bool
    {
        if ($account?->trashed() === true) {
            return false;
        }

        return $this->verb($user, 'set_type', $account);
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
