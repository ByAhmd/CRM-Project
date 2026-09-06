<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Activity;
use LogicException;

/**
 * Activities are immutable events (decision A-10): a row is written once by
 * ActivityRecorder and never edited. Deletion is allowed — it is a hard
 * delete gated by ActivityPolicy::delete and audited by the recorder.
 */
final class ActivityAppendOnlyObserver
{
    public function updating(Activity $activity): never
    {
        throw new LogicException('activities rows are immutable events and cannot be updated.');
    }
}
