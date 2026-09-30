<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskStatus;
use App\Enums\TaskUpdateKind;
use App\Observers\TaskUpdateAppendOnlyObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry of a task's thread: a progress entry (decision D-14, amended
 * 2026-09-28) — the assignee started the task, posted a progress note,
 * completed it, or someone reopened it — or a comment from anyone who may
 * view the task (D-17), told apart by `kind`. Append-only: written by
 * TaskService, never edited or deleted (TaskUpdateAppendOnlyObserver). There
 * is no `updated_at` column, so Eloquent stamps `created_at` alone.
 *
 * `status` is the status the entry moved the task to — null for a plain
 * progress note and for every comment — and `body` the author's plain text.
 * The author is null once their account is gone.
 *
 * @property TaskUpdateKind $kind
 * @property ?TaskStatus $status
 * @property Carbon $created_at
 */
#[Fillable(['task_id', 'user_id', 'kind', 'status', 'body'])]
#[ObservedBy(TaskUpdateAppendOnlyObserver::class)]
final class TaskUpdate extends Model
{
    public const UPDATED_AT = null;

    /**
     * The column's database default, known before the row is read back, so
     * an entry written without a kind is a progress entry in memory too.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'progress',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TaskUpdateKind::class,
            'status' => TaskStatus::class,
            'created_at' => 'datetime',
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
     * Who wrote the entry; soft-deleted accounts still resolve so the log
     * can say whose it was.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function isComment(): bool
    {
        return $this->kind === TaskUpdateKind::Comment;
    }
}
