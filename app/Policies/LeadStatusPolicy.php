<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LeadStatus;
use App\Models\User;
use App\Policies\Concerns\ManagesSettings;
use App\Services\Settings\LeadStatusService;

/**
 * Lead statuses are configuration (decisions D-7, A-4): settings.manage
 * covers everything, with the guards the workflow and the schema depend on —
 * the default status, the single Converted status and any status still
 * referenced by a lead (including a soft-deleted one), by its status history
 * or by a scoring rule can never be deleted (DATABASE_DESIGN.md section 6:
 * referenced lookups are deactivated instead). LeadStatusService refuses the
 * same deletions; the policy keeps the action out of the UI and out of bulk
 * selections.
 */
final class LeadStatusPolicy
{
    use ManagesSettings {
        delete as private deleteAsSetting;
    }

    public function delete(User $user, ?LeadStatus $record = null): bool
    {
        if ($record !== null && ! app(LeadStatusService::class)->isDeletable($record)) {
            return false;
        }

        return $this->deleteAsSetting($user, $record);
    }
}
