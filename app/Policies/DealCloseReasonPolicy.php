<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DealCloseReason;
use App\Models\User;
use App\Policies\Concerns\ManagesSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * Deal close reasons are configurable lookups (decision A-4): one permission,
 * settings.manage, covers viewing, editing and reordering. A reason that a
 * closed deal still records (including a soft-deleted deal, which keeps its
 * foreign key) can never be deleted — deals reference it with RESTRICT, so
 * the policy hides the action and bulk deletes skip the row
 * (DATABASE_DESIGN.md section 6: referenced lookups are deactivated instead).
 * Permanent deletion is never offered (D-13).
 */
final class DealCloseReasonPolicy
{
    use ManagesSettings {
        delete as private deleteAsSetting;
    }

    public function delete(User $user, ?Model $record = null): bool
    {
        if ($record instanceof DealCloseReason && $record->deals()->withTrashed()->exists()) {
            return false;
        }

        return $this->deleteAsSetting($user, $record);
    }
}
