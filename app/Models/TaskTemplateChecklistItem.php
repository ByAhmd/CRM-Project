<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a routine task's checklist (decisions D-22, D-25), copied
 * unticked into every task the routine creates.
 *
 * Configuration owned by its routine and written by RoutineTaskService only,
 * which records every change on the routine in the ledger. There are no
 * ticks: a routine's checklist is never worked on, only copied.
 *
 * @property int $task_template_id
 * @property string $body
 * @property int $position
 */
#[Fillable(['task_template_id', 'body', 'position'])]
final class TaskTemplateChecklistItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TaskTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class, 'task_template_id');
    }
}
