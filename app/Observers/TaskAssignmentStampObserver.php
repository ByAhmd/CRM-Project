<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Task;

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

        $task->assigned_at = $task->getAttribute('assignee_id') === null ? null : $task->freshTimestamp();
    }
}
