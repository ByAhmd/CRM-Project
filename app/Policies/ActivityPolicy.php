<?php

declare(strict_types=1);

namespace App\Policies;

use App\Contracts\OwnedRecord;
use App\Models\Activity;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Database\Eloquent\Model;

/**
 * Activities (decisions D-4, A-10, D-13).
 *
 * Reading follows two paths, either of which is enough: the activity's own
 * visibility scope (owner / team / all through RecordVisibilityResolver) or
 * the visibility of a record it is linked to — an activity on a deal the
 * user may read is readable even when someone else owns it. Activities are
 * immutable, so `update` is always refused; `delete` is a hard delete that
 * needs `activity.delete` plus one of the two reading paths. There are no
 * soft deletes, so nothing is ever restored.
 */
final class ActivityPolicy
{
    use ChecksPermissions;

    protected function permissionGroup(): string
    {
        return Activity::permissionGroup();
    }

    public function view(User $user, Model&OwnedRecord $record): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return $this->resolver()->canRead($user, $record) || $this->subjectReadable($user, $record);
    }

    public function update(User $user, ?Activity $activity = null): bool
    {
        return false;
    }

    public function delete(User $user, ?Activity $activity = null): bool
    {
        if (! $user->can($this->permission('delete'))) {
            return false;
        }

        return $activity === null
            || $this->resolver()->canWrite($user, $activity)
            || $this->subjectReadable($user, $activity);
    }

    public function restore(User $user, ?Activity $activity = null): bool
    {
        return false;
    }

    public function export(User $user): bool
    {
        return $user->can($this->permission('export'));
    }

    /** Whether the user may read at least one of the records the activity is linked to. */
    private function subjectReadable(User $user, Model $activity): bool
    {
        if (! $activity instanceof Activity) {
            return false;
        }

        foreach ($activity->linkedRecords() as $subject) {
            if ($user->can('view', $subject)) {
                return true;
            }
        }

        return false;
    }
}
