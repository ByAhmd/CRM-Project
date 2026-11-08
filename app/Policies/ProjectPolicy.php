<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ManagesSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * Projects are configurable lookups (decisions D-30, A-4): settings.manage
 * covers every operation; permanent deletion is never granted (D-13).
 *
 * The default project can never be deleted (there must always be one), and
 * neither can a project still referenced by a lead, contact, account or deal
 * (soft-deleted ones keep their foreign key) — the RESTRICT keys refuse it,
 * so the policy hides the action and bulk deletes skip it; such a project is
 * deactivated instead. Entering a project needs no permission of its own:
 * every Sales user switches between the active projects (D-30).
 */
final class ProjectPolicy
{
    use ManagesSettings {
        delete as private deleteAsSetting;
    }

    public function delete(User $user, ?Model $record = null): bool
    {
        if ($record instanceof Project && ($record->is_default || $this->isInUse($record))) {
            return false;
        }

        return $this->deleteAsSetting($user, $record);
    }

    private function isInUse(Project $project): bool
    {
        $id = $project->getKey();

        return Lead::withTrashed()->where('project_id', $id)->exists()
            || Contact::withTrashed()->where('project_id', $id)->exists()
            || Account::withTrashed()->where('project_id', $id)->exists()
            || Deal::withTrashed()->where('project_id', $id)->exists();
    }
}
