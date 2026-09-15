<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\LeadScoringRuleKind;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadScoringRule;
use App\Models\LeadSource;
use App\Models\User;
use App\Policies\Concerns\ManagesSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * Lead sources are configurable lookups (decision A-4): settings.manage
 * covers every operation; permanent deletion is never granted (D-13).
 *
 * A source still referenced by a lead or a deal (soft-deleted ones keep
 * their foreign key) or by a scoring rule can never be deleted — the RESTRICT
 * keys refuse it, so the policy hides the action and bulk deletes skip it
 * (DATABASE_DESIGN.md section 6: referenced lookups are deactivated instead).
 */
final class LeadSourcePolicy
{
    use ManagesSettings {
        delete as private deleteAsSetting;
    }

    public function delete(User $user, ?Model $record = null): bool
    {
        if ($record instanceof LeadSource && $this->isInUse($record)) {
            return false;
        }

        return $this->deleteAsSetting($user, $record);
    }

    private function isInUse(LeadSource $source): bool
    {
        $id = $source->getKey();

        return Lead::withTrashed()->where('lead_source_id', $id)->exists()
            || Deal::withTrashed()->where('lead_source_id', $id)->exists()
            || LeadScoringRule::query()->where('kind', LeadScoringRuleKind::Source->value)->where('reference_id', $id)->exists();
    }
}
