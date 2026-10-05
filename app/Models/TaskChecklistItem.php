<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardsWorkflowFields;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of a task's checklist (decision D-25).
 *
 * Owned by its task and written by TaskService only (through TaskChecklist):
 * the body and the position by whoever may edit the task, the tick — when
 * and by whom — by whoever may report on it, and only while the task is
 * open. The tick columns are workflow-guarded: an update that touches them
 * outside the service's guard lift throws. Not audited on its own — the
 * service writes each change to the ledger on the task (`task.checklist_*`
 * events), as it does for the thread.
 *
 * @property int $task_id
 * @property string $body
 * @property int $position
 * @property ?Carbon $completed_at
 * @property ?int $completed_by
 */
#[Fillable(['task_id', 'body', 'position'])]
final class TaskChecklistItem extends Model
{
    use GuardsWorkflowFields;

    /**
     * @return list<string>
     */
    public static function workflowGuardedAttributes(): array
    {
        return ['completed_at', 'completed_by'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Who ticked the item; soft-deleted accounts still resolve so the task
     * page can say whose tick it was.
     *
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by')->withTrashed();
    }

    public function isDone(): bool
    {
        return $this->completed_at !== null;
    }
}
