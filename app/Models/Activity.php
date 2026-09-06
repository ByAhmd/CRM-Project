<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\OwnedRecord;
use App\Enums\ActivityDirection;
use App\Enums\ActivityKind;
use App\Models\Concerns\HasOwner;
use App\Observers\ActivityAppendOnlyObserver;
use App\Support\RecordLabel;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An activity: an immutable event logged against a lead, a contact, an
 * account or a deal (decision A-10).
 *
 * Rows are written by ActivityRecorder only, which copies `kind` from the
 * type, requires at least one linked record and audits the creation.
 * ActivityAppendOnlyObserver refuses every update; deletion is a hard delete
 * gated by `activity.delete` and audited by the recorder. There is no
 * `updated_at` column, so Eloquent stamps `created_at` alone.
 *
 * @property ActivityKind $kind
 * @property ?ActivityDirection $direction
 * @property Carbon $occurred_at
 * @property ?array<string, mixed> $payload
 */
#[Fillable([
    'activity_type_id', 'kind', 'subject', 'body', 'direction', 'occurred_at', 'duration_minutes', 'outcome',
    'lead_id', 'contact_id', 'account_id', 'deal_id', 'task_id', 'note_id', 'owner_id', 'created_by', 'payload',
])]
#[ObservedBy(ActivityAppendOnlyObserver::class)]
final class Activity extends Model implements OwnedRecord
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    use HasOwner;

    public const UPDATED_AT = null;

    public static function permissionGroup(): string
    {
        return 'activity';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ActivityKind::class,
            'direction' => ActivityDirection::class,
            'occurred_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ActivityType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class, 'activity_type_id');
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
     * The task whose completion wrote this entry (decision A-10).
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The record the activity is "about": the most specific linked record,
     * in the order deal, lead, contact, account.
     */
    public function subjectRecord(): ?Model
    {
        return $this->linkedRecords()[0] ?? null;
    }

    public function subjectLabel(): ?string
    {
        $record = $this->subjectRecord();

        return $record === null ? null : RecordLabel::of($record);
    }

    /**
     * Every linked record that still exists, most specific first.
     *
     * @return list<Model>
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
     * The foreign key column an activity uses to link to the given record.
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
     * Activities linked to the given lead, contact, account or deal.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    protected function scopeForSubject(Builder $query, Model $subject): Builder
    {
        $column = self::subjectColumnFor($subject);

        if ($column === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($query->qualifyColumn($column), $subject->getKey());
    }
}
