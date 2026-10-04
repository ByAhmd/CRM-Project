<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\TaskUpdateKind;
use App\Models\TaskUpdate;
use App\Models\TaskUpdateMention;
use LogicException;

/**
 * Mentions in task comments are append-only (decision D-20), like the
 * thread they belong to: a mention is written once by TaskService with its
 * comment and never edited or removed, so the participation it gives lasts
 * as long as the task. The rows leave only with their comment, their task or
 * their user, through the foreign keys.
 *
 * A mention opens its task to the mentioned user through the denormalised
 * `task_id`, which no foreign key ties to the comment's own task — so a row
 * is written only for a comment (never a progress entry) and only with that
 * comment's task.
 */
final class TaskUpdateMentionAppendOnlyObserver
{
    public function creating(TaskUpdateMention $mention): void
    {
        $comment = TaskUpdate::query()
            ->whereKey($mention->task_update_id)
            ->first(['id', 'task_id', 'kind']);

        if ($comment === null || $comment->kind !== TaskUpdateKind::Comment || (int) $comment->task_id !== (int) $mention->task_id) {
            throw new LogicException('A task_update_mentions row belongs to a comment, with that comment\'s task.');
        }
    }

    public function updating(TaskUpdateMention $mention): never
    {
        throw new LogicException('task_update_mentions rows are append-only.');
    }

    public function deleting(TaskUpdateMention $mention): never
    {
        throw new LogicException('task_update_mentions rows are append-only.');
    }
}
