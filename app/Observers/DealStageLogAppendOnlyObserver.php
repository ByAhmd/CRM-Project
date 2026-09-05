<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\DealStageLog;
use LogicException;

/**
 * Deal stage history is append-only (decision D-8).
 */
final class DealStageLogAppendOnlyObserver
{
    public function updating(DealStageLog $log): never
    {
        throw new LogicException('deal_stage_logs rows are append-only.');
    }

    public function deleting(DealStageLog $log): never
    {
        throw new LogicException('deal_stage_logs rows are append-only.');
    }
}
