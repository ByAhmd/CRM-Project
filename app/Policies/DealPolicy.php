<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Deal;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Deals (D-4, D-8, D-13): permission + visibility scope through ChecksPermissions,
 * plus the deal verbs. A closed deal is frozen — it is reopened first, and
 * reopening is the `close` verb applied in reverse.
 */
final class DealPolicy
{
    use ChecksPermissions;

    protected function permissionGroup(): string
    {
        return Deal::permissionGroup();
    }

    public function update(User $user, ?Deal $deal = null): bool
    {
        if ($deal?->isClosed() === true) {
            return false;
        }

        return $user->can($this->permission('update')) && $this->reaches($user, $deal);
    }

    public function changeStage(User $user, ?Deal $deal = null): bool
    {
        if ($deal?->isClosed() === true) {
            return false;
        }

        return $this->verb($user, 'change_stage', $deal);
    }

    /** Win or lose the deal. */
    public function close(User $user, ?Deal $deal = null): bool
    {
        if ($deal?->isClosed() === true) {
            return false;
        }

        return $this->verb($user, 'close', $deal);
    }

    public function reopen(User $user, ?Deal $deal = null): bool
    {
        if ($deal !== null && ! $deal->isClosed()) {
            return false;
        }

        return $this->verb($user, 'close', $deal);
    }

    public function assign(User $user, ?Deal $deal = null): bool
    {
        return $this->verb($user, 'assign', $deal);
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
