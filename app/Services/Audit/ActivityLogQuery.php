<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read paths over the audit ledger (decision A-5).
 */
final class ActivityLogQuery
{
    /**
     * @return Builder<ActivityLog>
     */
    public function all(): Builder
    {
        return ActivityLog::query()->latest('created_at')->latest('id');
    }

    /**
     * @return Builder<ActivityLog>
     */
    public function forSubject(Model $subject): Builder
    {
        return $this->all()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }

    /**
     * @return Builder<ActivityLog>
     */
    public function forCauser(User $user): Builder
    {
        return $this->all()
            ->where('causer_type', $user->getMorphClass())
            ->where('causer_id', $user->getKey());
    }
}
