<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\TaskUpdate;
use LogicException;

/**
 * A task's progress log is append-only (decision D-14, amended 2026-09-28):
 * an entry is written once by TaskService and never edited or removed. The
 * rows leave only with their task, through the foreign key.
 */
final class TaskUpdateAppendOnlyObserver
{
    public function updating(TaskUpdate $update): never
    {
        throw new LogicException('task_updates rows are append-only.');
    }

    public function deleting(TaskUpdate $update): never
    {
        throw new LogicException('task_updates rows are append-only.');
    }
}
