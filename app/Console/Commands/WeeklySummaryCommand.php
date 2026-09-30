<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tasks\WeeklySummary;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Sends the weekly summary of the last seven days (decision D-18) to every
 * active user who holds `task.assign`, each in their own locale, with the
 * system health part for super admins only. Scheduled Thursdays at 22:30 in
 * routes/console.php, half an hour after the weekly backup it reports on.
 * Each run sends a fresh summary; it is not idempotent by design.
 */
final class WeeklySummaryCommand extends Command
{
    protected $signature = 'crm:weekly-summary';

    protected $description = 'Send the weekly task summary (and system health for super admins) to everyone who assigns tasks';

    public function handle(WeeklySummary $summary): int
    {
        $count = $summary->send(CarbonImmutable::now());

        $this->info(sprintf('[tasks] weekly summary sent to %d recipient%s.', $count, $count === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
