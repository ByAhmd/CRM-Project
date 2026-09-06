<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Leads\LeadStaleService;
use Illuminate\Console\Command;

/**
 * Tells owners once about open leads that have had no activity for
 * crm.leads.stale_days (plan section 3.6, decision D-1). Idempotent —
 * LeadStaleService stamps stale_notified_at — so the daily entry in
 * routes/console.php may be re-run after a failure without repeating.
 */
final class NotifyStaleLeads extends Command
{
    protected $signature = 'leads:notify-stale';

    protected $description = 'Notify owners of open leads that have had no activity for the configured number of days (idempotent)';

    public function handle(LeadStaleService $stale): int
    {
        $count = $stale->notify(now());

        $this->info(sprintf('[leads] %d stale lead notification%s sent.', $count, $count === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
