<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\OwnedRecord;
use App\Enums\ActivityLogEvent;
use App\Enums\RecurrenceFrequency;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Concerns\GuardsWorkflowFields;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasOwner;
use App\Support\RecordLabel;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * series.
 *
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
#[Fillable([
    'title', 'description', 'kind', 'status', 'priority', 'due_at', 'starts_at', 'ends_at', 'reminder_at',
    'assignee_id', 'lead_id', 'contact_id', 'account_id', 'deal_id',
    'recurrence_frequency', 'recurrence_interval', 'recurrence_ends_at', 'series_id', 'created_by',
])]
final class Task extends Model implements OwnedRecord
{
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
     * The timeline entries written for this task (its completion), newest first.
     *
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->orderByDesc('occurred_at')->orderByDesc('id');
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
