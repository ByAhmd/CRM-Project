<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\User;
use App\Policies\Concerns\ManagesSettings;

/**
 * Activity types are configuration (decision A-4): settings.manage covers
 * everything, except that the seeded system row of each kind and any type a
 * logged activity still uses can never be deleted — activities reference
 * their type with RESTRICT, so the policy hides the action and bulk deletes
 * skip the row (DATABASE_DESIGN.md section 6: referenced lookups are
 * deactivated instead). Permanent deletion is never offered (D-13).
 */
final class ActivityTypePolicy
{
    use ManagesSettings {
        delete as private deleteAsSetting;
    }

    public function delete(User $user, ?ActivityType $record = null): bool
    {
        if ($record !== null && ($record->is_system || Activity::query()->where('activity_type_id', $record->getKey())->exists())) {
            return false;
        }

        return $this->deleteAsSetting($user, $record);
    }
}
