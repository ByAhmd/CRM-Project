<?php

declare(strict_types=1);

namespace App\Policies;

use App\Contracts\OwnedRecord;
use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Database\Eloquent\Model;

/**
 * Tasks (decisions D-4, A-10, D-13).
 *
 * Reading follows two paths, either of which is enough: the task's own
 * visibility scope (assignee / team / all through RecordVisibilityResolver,
 * the assignee being the owner column) or the visibility of a record it is
 * linked to — a task on a deal the user may read is readable even when
 * someone else is assigned. Writing needs the `task.*` key plus the actor's
 * reach over the assignee; the status verbs are `update` under their own
 * names, and a completed or cancelled task stays editable so it can be
 * reopened. There is no `task.restore` key: restoring undoes a delete, so it
 * is granted with `task.delete`. Permanent deletion is never granted.
 */
final class TaskPolicy
{
    use ChecksPermissions;

    protected function permissionGroup(): string
    {
        return Task::permissionGroup();
    }

    public function view(User $user, Model&OwnedRecord $record): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return $this->resolver()->canRead($user, $record) || $this->subjectReadable($user, $record);
    }

    public function complete(User $user, ?Task $task = null): bool
    {
        return $this->update($user, $task);
    }

    public function cancel(User $user, ?Task $task = null): bool
    {
        return $this->update($user, $task);
    }

    public function reopen(User $user, ?Task $task = null): bool
    {
        return $this->update($user, $task);
    }

    public function restore(User $user, ?Task $task = null): bool
    {
        return $this->delete($user, $task);
    }

    public function assign(User $user, ?Task $task = null): bool
    {
        return $this->verb($user, 'assign', $task);
    }

    public function export(User $user): bool
    {
        return $user->can($this->permission('export'));
    }

    /** Whether the user may read at least one of the records the task is linked to. */
    private function subjectReadable(User $user, Model $task): bool
    {
        if (! $task instanceof Task) {
            return false;
        }

        foreach ($task->linkedRecords() as $subject) {
            if ($user->can('view', $subject)) {
                return true;
            }
        }

        return false;
    }
}
