<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ActivityType;
use App\Models\User;
use App\Policies\Concerns\ManagesSettings;

/**
 * Activity types are configuration (decision A-4): settings.manage covers
 * everything, except that the seeded system row of each kind can never be
 * deleted. Permanent deletion is never offered (D-13).
 */
final class ActivityTypePolicy
{
    use ManagesSettings {
        delete as private deleteAsSetting;
    }

    public function delete(User $user, ?ActivityType $record = null): bool
    {
        if ($record !== null && $record->is_system) {
            return false;
        }

        return $this->deleteAsSetting($user, $record);
    }
}
