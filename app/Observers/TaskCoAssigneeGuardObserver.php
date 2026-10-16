<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Task;
use App\Models\TaskCoAssignee;

/**
 * Keeps a shared task's assignees distinct (decision D-28): the first
 * assignee (`tasks.assignee_id`) is never also one of its co-assignees.
 *
 * Whenever a task's first assignee changes — reassigned through
 * RecordAssignmentService (the assign actions, the owner field, an edit
 * through TaskService), a co-assignee moved up among them — a co-assignee row
 * for that same user is removed, so nobody is counted twice by the reports or
 * told twice by the reminders. The row is still there while the task saves,
 * so TaskAssignmentStampObserver can carry its `assigned_at` over (D-21).
 * A task being created has no co-assignee rows yet (they are written after
 * it), so creation costs no query here. The rows are deleted one by one, as
 * models, so the visibility memo hears of it (RecordVisibilityResolver).
 */
final class TaskCoAssigneeGuardObserver
{
    public function updated(Task $task): void
    {
        if ($task->assignee_id === null || ! $task->wasChanged('assignee_id')) {
            return;
        }

        TaskCoAssignee::query()
            ->where('task_id', $task->getKey())
            ->where('user_id', $task->assignee_id)
            ->get()
            ->each(static fn (TaskCoAssignee $row): ?bool => $row->delete());
    }
}
