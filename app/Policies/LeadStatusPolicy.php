<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LeadStatus;
use App\Models\User;
use App\Policies\Concerns\ManagesSettings;

/**
 * Lead statuses are configuration (decisions D-7, A-4): settings.manage
 * covers everything, with two guards the workflow depends on — the default
 * status and the single Converted status can never be deleted.
 * LeadStatusService refuses the same deletions; the policy keeps the action
 * out of the UI and out of bulk selections.
 */
final class LeadStatusPolicy
{
    use ManagesSettings {
        delete as private deleteAsSetting;
    }

    public function delete(User $user, ?LeadStatus $record = null): bool
    {
        if ($record !== null && ($record->isDefault() || $record->isConverted())) {
            return false;
        }

        return $this->deleteAsSetting($user, $record);
    }
}
