<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lead;
use App\Services\Leads\LeadScoringService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-computes the score of every unconverted lead (decision D-7). Dispatched
 * when scoring rules change and by `leads:rescore`; unique so a burst of rule
 * edits queues one run. Converted leads keep their final score.
 */
final class RescoreLeads implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(LeadScoringService $scoring): void
    {
        $scoring->refresh();

        Lead::query()
            ->whereNull('converted_at')
            ->select(['id'])
            ->chunkById(200, function ($leads) use ($scoring): void {
                foreach ($leads as $stub) {
                    $lead = Lead::query()->find($stub->getKey());

                    if ($lead instanceof Lead) {
                        $scoring->rescore($lead);
                    }
                }
            });
    }
}
