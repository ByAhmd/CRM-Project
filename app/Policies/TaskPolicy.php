<?php

declare(strict_types=1);

namespace App\Policies;

use App\Contracts\OwnedRecord;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Database\Eloquent\Model;

/**
 * Tasks (decisions D-4, A-10, D-13, D-14).
 *
 * Reading follows two paths, either of which is enough: the task's own
 * visibility scope (assignee / team / all through RecordVisibilityResolver,
 * the assignee being the owner column) or the visibility of a record it is
 * linked to — a task on a deal the user may read is readable even when
 * someone else is assigned. Writing needs the `task.*` key plus the actor's
 * reach over the assignee. A soft-deleted task is frozen: every write but
 * the restore refuses it (D-13). There is no `task.restore` key: restoring
 * undoes a delete, so it is granted with `task.delete`. Permanent deletion
 * is never granted.
 *
 * A handed-out task belongs to its assigner (D-14 amendment, 2026-09-28):
 * when the user is the assignee and `assigned_by` names someone else, and
 * the user does not hold `task.assign`, `update` (the details, the calendar
 * move), `cancel`, `delete` and `restore` refuse them. What they may do is
 * `progress` — the former `update` rule, `task.update` plus reach — which
 * governs starting the task, posting progress updates, completing it and
 * reopening a task that was completed; a cancelled task reopens only for
 * those who may edit it. The assigner, administrators and every other
 * holder of `task.update` within reach (a team manager) keep editing; a
 * task a user created for themselves stays fully theirs, and a task from
 * before `assigned_by` existed (null) is not handed out.
 */
final class TaskPolicy
{
    use ChecksPermissions {
        update as private updateWithinReach;
        delete as private deleteWithinReach;
    }

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

    /** The details: title, description, kind, priority, dates, reminder, related record, recurrence. */
    public function update(User $user, (Model&OwnedRecord)|null $record = null): bool
    {
        return $this->updateWithinReach($user, $record) && ! $this->handedOutTo($user, $record);
    }

    public function delete(User $user, (Model&OwnedRecord)|null $record = null): bool
    {
        return $this->deleteWithinReach($user, $record) && ! $this->handedOutTo($user, $record);
    }

    public function restore(User $user, ?Task $task = null): bool
    {
        return $this->delete($user, $task);
    }

    /**
     * Reporting on the work: start, post an update, complete, reopen a
     * completed task. The former `update` rule — `task.update` within reach,
     * never on a deleted task — which the handed-out assignee keeps.
     */
    public function progress(User $user, ?Task $task = null): bool
    {
        return $this->updateWithinReach($user, $task);
    }

    public function start(User $user, ?Task $task = null): bool
    {
        return $this->progress($user, $task);
    }

    public function postUpdate(User $user, ?Task $task = null): bool
    {
        return $this->progress($user, $task);
    }

    public function complete(User $user, ?Task $task = null): bool
    {
        return $this->progress($user, $task);
    }

    public function cancel(User $user, ?Task $task = null): bool
    {
        return $this->update($user, $task);
    }

    /**
     * A completed task reopens for whoever may report on it — the assignee
     * who completed it by mistake included; a cancelled one only for those
     * who may edit it, since cancelling was theirs to decide.
     */
    public function reopen(User $user, ?Task $task = null): bool
    {
        if ($task !== null && $task->status === TaskStatus::Completed) {
            return $this->progress($user, $task);
        }

        return $this->update($user, $task);
    }

    public function assign(User $user, ?Task $task = null): bool
    {
        return $this->verb($user, 'assign', $task);
    }

    public function export(User $user): bool
    {
        return $user->can($this->permission('export'));
    }

    /**
     * Whether the task was handed out to the user by someone else and the
     * user may not hand tasks out themselves — the case where the task's
     * details and its fate stay with the assigner.
     */
    private function handedOutTo(User $user, ?Model $record): bool
    {
        return $record instanceof Task
            && $record->isHandedOutTo($user)
            && ! $user->can(Permission::TaskAssign->value);
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
