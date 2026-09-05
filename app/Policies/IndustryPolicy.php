<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Industry;
use App\Models\User;
use App\Policies\Concerns\ManagesSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * Industries are configuration (decision A-4): settings.manage covers
 * everything, except that a sector still referenced by an account (including
 * a soft-deleted one, which keeps its FK) can never be deleted — the database
 * restricts it and the policy hides the action before it is offered.
 */
final class IndustryPolicy
{
    use ManagesSettings;

    public function delete(User $user, ?Model $record = null): bool
    {
        if ($record instanceof Industry && $record->accounts()->withTrashed()->exists()) {
            return false;
        }

        return $this->viewAny($user);
    }
}
