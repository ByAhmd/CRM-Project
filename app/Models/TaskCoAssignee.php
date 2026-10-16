<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One further assignee of a shared task (decision D-28). The task's first
 * assignee stays in `tasks.assignee_id`; every other employee who holds the
 * same task is a row here, with who added them (`assigned_by`, D-14) and when
 * the task reached them (`assigned_at`, the start of their time to finish,
 * D-21). A row makes its user an owner of the task for visibility
 * (Task::coOwnerModel(), RecordVisibilityResolver), so they find it in My
 * Work, may open it, report progress on it and complete it, exactly as the
 * first assignee does.
 *
 * Added by TaskService and TaskRecurrence only, removed by TaskService and
 * by TaskCoAssigneeGuardObserver — the first assignee is never also a row,
 * so whenever the first assignee changes (RecordAssignmentService, on every
 * path) the new one's row goes. Changes of co-assignees reach the ledger as
 * `task.assignees_changed` from the service, so the model carries no
 * LogsActivity of its own. There are no Eloquent timestamps: `assigned_at`
 * is the row's moment.
 *
 * @property int $task_id
 * @property int $user_id
 * @property ?int $assigned_by
 * @property Carbon $assigned_at
 */
#[Fillable(['task_id', 'user_id', 'assigned_by', 'assigned_at'])]
final class TaskCoAssignee extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    /**
     * The employee; a soft-deleted account still resolves so the task keeps
     * naming who held it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Who added the employee to the task (D-14); null once that account is
     * removed from the database.
     *
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by')->withTrashed();
    }
}
