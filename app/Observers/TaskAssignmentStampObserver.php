<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Task;
use App\Models\TaskCoAssignee;

/**
 * Keeps `tasks.assigned_at` — when the task reached its current assignee —
 * true on every save (decision D-21).
 *
 * A task created with an assignee is stamped now, a change of assignee
 * restamps it and unassigning clears it; nothing else moves it, whatever a
 * caller put in the attribute. Being a model observer, it holds on every
 * path that saves a task through Eloquent: TaskService create and edit,
 * RecordAssignmentService (the assign actions and the owner field),
 * TaskRecurrence's next occurrence, routine tasks, any importer and the
 * factories. The task performance report reads it as the start of the time
 * to finish.
 *
 * A co-assignee of a shared task who becomes its first assignee (D-28) has
 * held the task since their own row's `assigned_at`, so the task carries that
 * moment over rather than restarting their time to finish; their row is
 * still there at this point (TaskCoAssigneeGuardObserver removes it once the
 * save is done), so this costs one query only when the assignee changes.
 */
final class TaskAssignmentStampObserver
{
    public function creating(Task $task): void
    {
        $task->assigned_at = $task->getAttribute('assignee_id') === null ? null : $task->freshTimestamp();
    }

    public function updating(Task $task): void
    {
        if (! $task->isDirty('assignee_id')) {
            // A caller cannot move the stamp on its own.
            if ($task->isDirty('assigned_at')) {
                $task->assigned_at = $task->getOriginal('assigned_at');
            }

            return;
        }

        $assigneeId = $task->getAttribute('assignee_id');

        if ($assigneeId === null) {
            $task->assigned_at = null;

            return;
        }

        $heldSince = TaskCoAssignee::query()
            ->where('task_id', $task->getKey())
            ->where('user_id', $assigneeId)
            ->value('assigned_at');

        $task->assigned_at = $heldSince ?? $task->freshTimestamp();
    }
}
