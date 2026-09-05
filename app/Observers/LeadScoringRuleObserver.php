<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RescoreLeads;
use App\Models\LeadScoringRule;
use App\Services\Leads\LeadScoringService;

/**
 * Keeps each rule shaped for its kind (a source rule carries no field, a
 * field rule no reference, …) and re-scores every open lead in the
 * background after any change (D-7), so a list sorted by score never shows
 * stale numbers.
 */
final class LeadScoringRuleObserver
{
    public function __construct(
        private readonly LeadScoringService $scoring,
    ) {}

    public function saving(LeadScoringRule $rule): void
    {
        $kind = $rule->kind;

        if (! $kind->usesReference()) {
            $rule->reference_id = null;
        }

        if (! $kind->usesField()) {
            $rule->field = null;
        }

        if (! $kind->usesDays()) {
            $rule->within_days = null;
        }
    }

    public function saved(LeadScoringRule $rule): void
    {
        $this->scoring->refresh();
        RescoreLeads::dispatch();
    }

    public function deleted(LeadScoringRule $rule): void
    {
        $this->scoring->refresh();
        RescoreLeads::dispatch();
    }
}
