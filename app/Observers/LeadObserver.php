<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Lead;
use App\Services\Leads\LeadScoringService;

/**
 * Keeps the computed score current on every save (decision D-7). The
 * workflow columns are guarded by GuardsWorkflowFields on the model itself.
 */
final class LeadObserver
{
    public function __construct(
        private readonly LeadScoringService $scoring,
    ) {}

    public function saving(Lead $lead): void
    {
        $lead->score = $this->scoring->calculate($lead);
        $lead->scored_at = now();
    }
}
