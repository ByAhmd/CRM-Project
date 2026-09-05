<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\LeadStatusLog;
use LogicException;

/**
 * Lead status history is append-only (decision D-7).
 */
final class LeadStatusLogAppendOnlyObserver
{
    public function updating(LeadStatusLog $log): never
    {
        throw new LogicException('lead_status_logs rows are append-only.');
    }

    public function deleting(LeadStatusLog $log): never
    {
        throw new LogicException('lead_status_logs rows are append-only.');
    }
}
