<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\TaskUpdateMentionAppendOnlyObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A colleague mentioned in a task comment (decision D-20). The mention makes
 * the user a participant of the task: they may open it, read its thread and
 * comment on it (TaskPolicy::view, TaskResource::constrainToReadable), and
 * they hear about later comments like every participant.
 *
 * Append-only like the thread it annotates: written by TaskService::comment()
 * in the comment's transaction, never edited or deleted
 * (TaskUpdateMentionAppendOnlyObserver), so the access it gives lasts as long
 * as the task. `task_id` repeats the comment's task for the read path. There
 * is no `updated_at` column, so Eloquent stamps `created_at` alone.
 *
 * @property Carbon $created_at
 */
#[Fillable(['task_update_id', 'task_id', 'user_id'])]
#[ObservedBy(TaskUpdateMentionAppendOnlyObserver::class)]
final class TaskUpdateMention extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * The comment that holds the mention.
     *
     * @return BelongsTo<TaskUpdate, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(TaskUpdate::class, 'task_update_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * The mentioned user; soft-deleted accounts still resolve so the thread
     * can still mark the name the comment holds.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
