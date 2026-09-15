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
 * when scoring rules change and once a day by the scheduler, so the points of
 * an activity-recency rule are withdrawn once a lead's last activity leaves
 * the rule's window. Converted leads keep their final score.
 *
 * One job rescores one batch of leads, read whole (no query per lead), and
 * queues the next batch after the last id it handled: the scheduler drains
 * the queue in short runs on the shared host (D-1), so no single job may
 * outlive the drain window however many leads there are. The unique id is
 * the batch cursor, so a burst of rule edits queues one run from the start
 * while a run's continuation is never mistaken for a duplicate.
 */
final class RescoreLeads implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int BATCH_SIZE = 500;

    public int $tries = 1;

    /**
     * 45 seconds: inside the scheduler drain's queue:work --max-time=50
     * window (routes/console.php). The worker checks --max-time only between
     * jobs, so a batch started late in the window runs past it by at most
     * this timeout; a timeout longer than the window (the former 55) let one
     * batch alone outlive a whole drain and overlap the next minute's run.
     * It also stays well under the database queue's retry_after (90), so a
     * slow batch is killed before it could be released to a second worker.
     */
    public int $timeout = 45;

    public function __construct(
        public readonly int $afterId = 0,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->afterId;
    }

    public function handle(LeadScoringService $scoring): void
    {
        $scoring->refresh();

        $leads = Lead::query()
            ->whereNull('converted_at')
            ->where('id', '>', $this->afterId)
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        foreach ($leads as $lead) {
            $scoring->rescore($lead);
        }

        $last = $leads->last();

        if ($leads->count() === self::BATCH_SIZE && $last instanceof Lead) {
            self::dispatch((int) $last->getKey());
        }
    }
}
