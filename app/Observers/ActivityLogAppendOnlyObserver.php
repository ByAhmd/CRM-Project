<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ActivityLog;
use LogicException;

/**
 * The audit ledger is append-only (decision A-5).
 *
 * Rows are written once; updating or deleting one through Eloquent is a bug,
 * not a feature, and fails loudly. Retention pruning (`activitylog:clean`)
 * works through the query builder and is the only sanctioned way a row leaves.
 */
final class ActivityLogAppendOnlyObserver
{
    public function updating(ActivityLog $log): never
    {
        throw new LogicException('The audit ledger is append-only: activity_log rows cannot be updated.');
    }

    public function deleting(ActivityLog $log): never
    {
        throw new LogicException('The audit ledger is append-only: activity_log rows cannot be deleted through the model.');
    }
}
