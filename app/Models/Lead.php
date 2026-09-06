<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\OwnedRecord;
use App\Enums\ActivityLogEvent;
use App\Enums\LeadPriority;
use App\Models\Concerns\GuardsWorkflowFields;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasNormalizedContactColumns;
use App\Models\Concerns\HasOwner;
use App\Models\Concerns\HasTags;
use App\Observers\LeadObserver;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A lead (decision D-7).
 *
 * @property LeadPriority $priority
 * @property ?Carbon $scored_at
 * @property ?Carbon $qualified_at
 * @property ?Carbon $converted_at
 * @property ?Carbon $last_activity_at
 * @property-read string $full_name
 * @property-read int $effective_score
 */
#[Fillable([
    'first_name', 'last_name', 'company_name', 'job_title', 'email', 'phone', 'website',
    'address_line', 'city', 'region', 'country', 'postal_code',
    'lead_source_id', 'lead_status_id', 'owner_id', 'priority', 'score_override', 'description', 'created_by',
])]
#[ObservedBy(LeadObserver::class)]
final class Lead extends Model implements OwnedRecord
{
    use GuardsWorkflowFields;
    use HasAttachments;

    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    use HasNormalizedContactColumns;
    use HasOwner;
    use HasTags;
    use LogsActivity;
    use SoftDeletes;

    /** @var list<string> */
    protected static array $recordEvents = ['created', 'updated', 'deleted', 'restored'];

    public static function permissionGroup(): string
    {
        return 'lead';
    }

    /**
     * @return list<string>
     */
    public static function workflowGuardedAttributes(): array
    {
        return [
            'lead_status_id', 'qualified_at', 'qualified_by',
            'converted_at', 'converted_by', 'converted_account_id', 'converted_contact_id', 'converted_deal_id',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => LeadPriority::class,
            'scored_at' => 'datetime',
            'qualified_at' => 'datetime',
            'converted_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(ActivityLogEvent::LeadUpdated->logName())
            ->logOnly([
                'first_name', 'last_name', 'company_name', 'job_title', 'email', 'phone', 'website',
                'lead_source_id', 'lead_status_id', 'owner_id', 'priority', 'score_override',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created' => ActivityLogEvent::LeadCreated->value,
            'deleted' => ActivityLogEvent::LeadDeleted->value,
            'restored' => ActivityLogEvent::LeadRestored->value,
            default => ActivityLogEvent::LeadUpdated->value,
        };
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.$this->last_name));
    }

    /**
     * @return Attribute<int<0, max>, never>
     */
    protected function effectiveScore(): Attribute
    {
        return Attribute::get(fn (): int => max(0, (int) ($this->score_override ?? $this->score)));
    }

    /**
     * @return BelongsTo<LeadStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'lead_status_id');
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    /**
     * @return HasMany<LeadStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(LeadStatusLog::class)->orderByDesc('changed_at')->orderByDesc('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function qualifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qualified_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function converter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function convertedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'converted_account_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function convertedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'converted_contact_id');
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function convertedDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'converted_deal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Activities logged against the lead, newest first (decision A-10).
     *
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * Pinned first, newest first (decision A-10).
     *
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class)->orderByDesc('is_pinned')->orderByDesc('created_at');
    }

    /**
     * Tasks linked to the lead, soonest due first (decision A-10).
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('due_at')->orderBy('id');
    }

    public function isConverted(): bool
    {
        return $this->converted_at !== null;
    }
}
