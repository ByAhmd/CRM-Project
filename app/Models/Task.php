<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\OwnedRecord;
use App\Contracts\SharesOwnership;
use App\Contracts\TracksAssigner;
use App\Enums\ActivityLogEvent;
use App\Enums\RecurrenceFrequency;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Concerns\GuardsWorkflowFields;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasOwner;
use App\Observers\TaskAssignmentStampObserver;
use App\Observers\TaskCoAssigneeGuardObserver;
use App\Support\RecordLabel;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A task or follow-up (decisions A-10, D-4).
 *
 * The assignee is the owner for visibility purposes, so `ownerColumn()` is
 * `assignee_id` and the trait's `owner()` relation points at the assignee.
 * A task may be linked to a lead, a contact, an account or a deal, or to
 * nothing at all (a personal to-do). `status`, `completed_at` and the two
 * notification stamps are written by TaskService and TaskReminderService
 * only — `status` is fillable so a new task starts Pending, and the workflow
 * guard refuses every later change outside those services; every other
 * attribute change reaches the audit ledger through LogsActivity. A
 * recurring task spawns its next occurrence on completion
 * (TaskRecurrence) and every occurrence points at the first task of the
 * series. `assigned_by` remembers who handed the task to its assignee
 * (D-14): written via forceFill by TaskService and RecordAssignmentService
 * only, never fillable, and cleared when the task is unassigned. A task
 * handed out to its assignee (isHandedOutTo) belongs to its assigner: the
 * assignee reports progress through the `updates` log instead of editing it
 * (D-14 amendment, 2026-09-28). `assigned_at` — when the task reached its
 * current assignee (D-21) — is written by TaskAssignmentStampObserver only.
 * `template_id` links a task to the routine that created it (D-22): written
 * via forceFill by RoutineTaskService only, never fillable, and cleared when
 * the routine is deleted. The checklist (D-25, `checklistItems`) is written
 * by TaskService only: its structure by whoever may edit the task, its ticks
 * by whoever may report on it while the task is open.
 *
 * A task may be shared (D-28): `assignee_id` is its first assignee — the one
 * it is filed under, the owner column — and `coAssignees` the other employees
 * who hold the same task, with its one status, checklist and thread. Every
 * assignee is an owner for visibility (SharesOwnership: the resolver reaches
 * the task through any of them), finds it in their own work (assignedTo())
 * and reports on it like the first; co-assignees are added by TaskService and
 * TaskRecurrence only and removed by TaskService and the guard that keeps the
 * first assignee from also being a co-assignee (TaskCoAssigneeGuardObserver,
 * on every change of the first assignee). Display code eager-loads
 * ASSIGNEE_RELATIONS and reads assignees().
 *
 * @property ?int $assigned_by
 * @property ?int $template_id
 * @property ?Carbon $assigned_at
 * @property TaskKind $kind
 * @property TaskStatus $status
 * @property TaskPriority $priority
 * @property RecurrenceFrequency $recurrence_frequency
 * @property ?Carbon $due_at
 * @property ?Carbon $starts_at
 * @property ?Carbon $ends_at
 * @property ?Carbon $completed_at
 * @property ?Carbon $reminder_at
 * @property ?Carbon $reminder_sent_at
 * @property ?Carbon $overdue_notified_at
 * @property ?Carbon $recurrence_ends_at
 */
#[ObservedBy([TaskAssignmentStampObserver::class, TaskCoAssigneeGuardObserver::class])]
#[Fillable([
    'title', 'description', 'kind', 'status', 'priority', 'due_at', 'starts_at', 'ends_at', 'reminder_at',
    'assignee_id', 'lead_id', 'contact_id', 'account_id', 'deal_id',
    'recurrence_frequency', 'recurrence_interval', 'recurrence_ends_at', 'series_id', 'created_by',
])]
final class Task extends Model implements SharesOwnership, TracksAssigner
{
    /** What a screen eager-loads to show every assignee of a task (D-28). */
    public const array ASSIGNEE_RELATIONS = ['assignee', 'coAssignees'];

    use GuardsWorkflowFields;
    use HasAttachments;

    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use HasOwner;
    use LogsActivity;
    use SoftDeletes;

    /** @var list<string> */
    protected static array $recordEvents = ['created', 'updated', 'deleted', 'restored'];

    public static function permissionGroup(): string
    {
        return 'task';
    }

    /** The assignee owns the task for visibility purposes (D-4). */
    public static function ownerColumn(): string
    {
        return 'assignee_id';
    }

    /** Who handed the task to its assignee (D-14). */
    public static function assignerColumn(): string
    {
        return 'assigned_by';
    }

    /** The further assignees of a shared task (D-28) own it too. */
    public static function coOwnerModel(): string
    {
        return TaskCoAssignee::class;
    }

    public static function coOwnerRecordColumn(): string
    {
        return 'task_id';
    }

    public static function coOwnerUserColumn(): string
    {
        return 'user_id';
    }

    /** Who added each co-assignee (D-14, D-28). */
    public static function coOwnerAssignerColumn(): string
    {
        return 'assigned_by';
    }

    /**
     * @return list<string>
     */
    public static function workflowGuardedAttributes(): array
    {
        return ['status', 'completed_at', 'reminder_sent_at', 'overdue_notified_at'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TaskKind::class,
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'recurrence_frequency' => RecurrenceFrequency::class,
            'due_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminder_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'recurrence_ends_at' => 'date',
            'assigned_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(ActivityLogEvent::TaskUpdated->logName())
            ->logOnly(['title', 'kind', 'status', 'priority', 'due_at', 'assignee_id', 'starts_at', 'ends_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created' => ActivityLogEvent::TaskCreated->value,
            'deleted' => ActivityLogEvent::TaskDeleted->value,
            'restored' => ActivityLogEvent::TaskRestored->value,
            default => ActivityLogEvent::TaskUpdated->value,
        };
    }

    /**
     * The assignee: the same relation as owner(), under its business name.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * The other employees who hold the same task (D-28), in the order they
     * were added; soft-deleted accounts still resolve so the task keeps naming
     * who held it. The pivot carries who added each one and when.
     *
     * @return BelongsToMany<User, $this>
     */
    public function coAssignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_co_assignees')
            ->withPivot(['assigned_by', 'assigned_at'])
            ->withTrashed()
            ->orderBy('task_co_assignees.id');
    }

    /**
     * The co-assignee rows themselves (D-28), for writes and the reports.
     *
     * @return HasMany<TaskCoAssignee, $this>
     */
    public function coAssigneeRows(): HasMany
    {
        return $this->hasMany(TaskCoAssignee::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who handed the task to its current assignee (D-14); null for a task
     * nobody assigned or one that was unassigned.
     *
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * The first task of the series this occurrence belongs to.
     *
     * @return BelongsTo<Task, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(self::class, 'series_id');
    }

    /**
     * The later occurrences spawned from this (first) task, oldest first.
     *
     * @return HasMany<Task, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(self::class, 'series_id')->orderBy('due_at')->orderBy('id');
    }

    /**
     * The routine task this task was created from (D-22); null for every
     * other task and once the routine is deleted.
     *
     * @return BelongsTo<TaskTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class, 'template_id');
    }

    /**
     * The timeline entries written for this task (its completion), newest first.
     *
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * The progress log (D-14 amendment, 2026-09-28): starts, progress notes,
     * completions and reopenings, newest first.
     *
     * @return HasMany<TaskUpdate, $this>
     */
    public function updates(): HasMany
    {
        return $this->hasMany(TaskUpdate::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * The checklist (D-25), in its order. Read it eagerly or through
     * TaskChecklist::counts() — never as a lazy property.
     *
     * @return HasMany<TaskChecklistItem, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Whether the task was handed out to the given user (D-14 amendment,
     * 2026-09-28): they are its assignee and someone else is recorded as the
     * assigner. A task a user created for themselves, and a task from before
     * `assigned_by` existed (null), are not handed out. TaskPolicy adds the
     * permission side: a holder of `task.assign` is never limited by it.
     */
    public function isHandedOutTo(User $user): bool
    {
        $userId = (int) $user->getKey();

        if ($this->assignee_id !== null && (int) $this->assignee_id === $userId) {
            return $this->assigned_by !== null && (int) $this->assigned_by !== $userId;
        }

        // A co-assignee (D-28) was handed the task by whoever added them.
        $assignerId = $this->coAssignerOf($userId);

        return $assignerId !== false && $assignerId !== null && $assignerId !== $userId;
    }

    /**
     * Every assignee of the task (D-28): the first, then the co-assignees in
     * the order they were added. Reads the eager-loaded ASSIGNEE_RELATIONS.
     *
     * @return list<User>
     */
    public function assignees(): array
    {
        $first = $this->assignee;

        return array_values(array_filter([
            $first,
            ...$this->coAssignees->reject(static fn (User $user): bool => $first !== null && $user->is($first))->all(),
        ]));
    }

    /**
     * The user ids of every assignee (D-28), the first one first; from the
     * eager-loaded co-assignees when present, otherwise in one query.
     *
     * @return list<int>
     */
    public function assigneeIds(): array
    {
        $coIds = $this->relationLoaded('coAssignees')
            ? $this->coAssignees->map(static fn (User $user): int => (int) $user->getKey())->all()
            : TaskCoAssignee::query()->where('task_id', $this->getKey())->orderBy('id')->pluck('user_id')->map(static fn (mixed $id): int => (int) $id)->all();

        $first = $this->assignee_id === null ? [] : [(int) $this->assignee_id];

        return array_values(array_unique([...$first, ...$coIds]));
    }

    /** Whether the user is one of the task's assignees, first or co-assignee (D-28). */
    public function isAssignedTo(User $user): bool
    {
        if ($this->assignee_id !== null && (int) $this->assignee_id === (int) $user->getKey()) {
            return true;
        }

        return $this->coAssignerOf((int) $user->getKey()) !== false;
    }

    /** Whether the task is shared: it has co-assignees (D-28). */
    public function isShared(): bool
    {
        return $this->relationLoaded('coAssignees')
            ? $this->coAssignees->isNotEmpty()
            : TaskCoAssignee::query()->where('task_id', $this->getKey())->exists();
    }

    /**
     * Who added the user as a co-assignee — null when nobody is recorded —
     * or false when the user is not a co-assignee of the task.
     */
    private function coAssignerOf(int $userId): int|false|null
    {
        if (! $this->exists) {
            return false;
        }

        if ($this->relationLoaded('coAssignees')) {
            $user = $this->coAssignees->first(static fn (User $coAssignee): bool => (int) $coAssignee->getKey() === $userId);

            if (! $user instanceof User) {
                return false;
            }

            $assignerId = $user->getRelationValue('pivot')?->getAttribute('assigned_by');

            return $assignerId === null ? null : (int) $assignerId;
        }

        $row = TaskCoAssignee::query()->where('task_id', $this->getKey())->where('user_id', $userId)->first(['assigned_by']);

        if (! $row instanceof TaskCoAssignee) {
            return false;
        }

        return $row->assigned_by === null ? null : (int) $row->assigned_by;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isClosed(): bool
    {
        return ! $this->isOpen();
    }

    /** Open, with a due date already behind the given moment (default: now). */
    public function isOverdue(?Carbon $now = null): bool
    {
        return $this->isOpen()
            && $this->due_at !== null
            && $this->due_at->lessThan($now ?? now());
    }

    /** Open and due on the same calendar day as the given moment (default: today). */
    public function isDueToday(?Carbon $now = null): bool
    {
        return $this->isOpen()
            && $this->due_at !== null
            && $this->due_at->isSameDay($now ?? now());
    }

    public function repeats(): bool
    {
        return $this->recurrence_frequency->repeats();
    }

    /**
     * The record the task is "about": the most specific linked record, in the
     * order deal, lead, contact, account. Null for a task about nothing.
     */
    public function subjectRecord(): (Model&OwnedRecord)|null
    {
        return $this->deal ?? $this->lead ?? $this->contact ?? $this->account;
    }

    public function subjectLabel(): ?string
    {
        $record = $this->subjectRecord();

        return $record === null ? null : RecordLabel::of($record);
    }

    /**
     * Every linked record that still exists, most specific first.
     *
     * @return list<Model&OwnedRecord>
     */
    public function linkedRecords(): array
    {
        return array_values(array_filter([
            $this->deal,
            $this->lead,
            $this->contact,
            $this->account,
        ]));
    }

    /**
     * The foreign key column a task uses to link to the given record.
     */
    public static function subjectColumnFor(Model $subject): ?string
    {
        return match ($subject::class) {
            Lead::class => 'lead_id',
            Contact::class => 'contact_id',
            Account::class => 'account_id',
            Deal::class => 'deal_id',
            default => null,
        };
    }

    /**
     * The status values that count as open, for queries built outside the
     * model (list tabs, navigation badge).
     *
     * @return list<string>
     */
    public static function openStatusValues(): array
    {
        return array_values(array_map(
            static fn (TaskStatus $status): string => $status->value,
            array_filter(TaskStatus::cases(), static fn (TaskStatus $status): bool => $status->isOpen()),
        ));
    }

    /**
     * The tasks the user holds (D-28): as the first assignee or as a
     * co-assignee — what "assigned to me" means everywhere (My Work, the
     * dashboards, the reminders, the reports).
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    protected function scopeAssignedTo(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        return $query->where(static function (Builder $nested) use ($query, $userId): void {
            $nested->where($query->qualifyColumn('assignee_id'), $userId)
                ->orWhereIn($query->qualifyColumn('id'), TaskCoAssignee::query()->select('task_id')->where('user_id', $userId));
        });
    }

    /**
     * Pending or in progress.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    protected function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), self::openStatusValues());
    }

    /**
     * Due within the given span, both ends inclusive.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    protected function scopeDueBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween($query->qualifyColumn('due_at'), [$from, $to]);
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    protected function scopeForAssignee(Builder $query, User $user): Builder
    {
        return $query->where($query->qualifyColumn('assignee_id'), $user->getKey());
    }
}
