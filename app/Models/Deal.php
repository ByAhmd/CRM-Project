<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\OwnedRecord;
use App\Enums\ActivityLogEvent;
use App\Enums\DealStatus;
use App\Enums\ForecastCategory;
use App\Models\Concerns\GuardsWorkflowFields;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasOwner;
use App\Models\Concerns\HasTags;
use App\Observers\DealObserver;
use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * A deal (decisions D-6, D-8).
 *
 * Moves through the stages of one pipeline. The stage, the derived status
 * and the close columns are stamped by DealStageWorkflow only (DealObserver
 * enforces the Open starting point on creation); the amount is the sum of
 * the line items when there are any and a manual figure otherwise.
 *
 * @property DealStatus $status
 * @property ForecastCategory $forecast_category
 * @property string $amount
 * @property ?Carbon $expected_close_date
 * @property ?Carbon $won_at
 * @property ?Carbon $lost_at
 * @property ?Carbon $last_activity_at
 * @property-read int $effective_probability
 * @property-read string $weighted_amount
 */
#[Fillable([
    'title', 'account_id', 'contact_id', 'pipeline_id', 'stage_id', 'owner_id',
    'amount', 'currency', 'probability', 'expected_close_date', 'forecast_category',
    'lead_source_id', 'lead_id', 'last_activity_at', 'description', 'created_by',
])]
#[ObservedBy(DealObserver::class)]
final class Deal extends Model implements OwnedRecord
{
    use GuardsWorkflowFields;
    use HasAttachments;

    /** @use HasFactory<DealFactory> */
    use HasFactory;

    use HasOwner;
    use HasTags;
    use LogsActivity;
    use SoftDeletes;

    /** @var list<string> */
    protected static array $recordEvents = ['created', 'updated', 'deleted', 'restored'];

    public static function permissionGroup(): string
    {
        return 'deal';
    }

    /**
     * @return list<string>
     */
    public static function workflowGuardedAttributes(): array
    {
        return ['stage_id', 'status', 'won_at', 'lost_at', 'close_reason_id', 'lost_notes'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DealStatus::class,
            'forecast_category' => ForecastCategory::class,
            'amount' => 'decimal:2',
            'expected_close_date' => 'date',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(ActivityLogEvent::DealUpdated->logName())
            ->logOnly([
                'title', 'account_id', 'contact_id', 'pipeline_id', 'stage_id', 'owner_id', 'status',
                'amount', 'currency', 'probability', 'expected_close_date', 'forecast_category',
                'lead_source_id', 'close_reason_id',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created' => ActivityLogEvent::DealCreated->value,
            'deleted' => ActivityLogEvent::DealDeleted->value,
            'restored' => ActivityLogEvent::DealRestored->value,
            default => ActivityLogEvent::DealUpdated->value,
        };
    }

    /**
     * The per-deal override, else the stage probability, else 0 (D-8).
     *
     * @return Attribute<int<0, 100>, never>
     */
    protected function effectiveProbability(): Attribute
    {
        return Attribute::get(function (): int {
            $probability = (int) ($this->probability ?? $this->stage->probability ?? 0);

            return max(0, min(100, $probability));
        });
    }

    /**
     * amount × effective probability, as a 2-decimal string (D-8).
     *
     * @return Attribute<numeric-string, never>
     */
    protected function weightedAmount(): Attribute
    {
        return Attribute::get(fn (): string => number_format(
            round((float) $this->amount * $this->effective_probability / 100, 2),
            2,
            '.',
            '',
        ));
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The primary contact; the others are attached through deal_contacts.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /**
     * @return BelongsTo<PipelineStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    /**
     * The lead this deal was converted from, if any (D-7).
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<DealCloseReason, $this>
     */
    public function closeReason(): BelongsTo
    {
        return $this->belongsTo(DealCloseReason::class, 'close_reason_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Activities logged against the deal, newest first (decision A-10).
     *
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * @return HasMany<DealStageLog, $this>
     */
    public function stageLogs(): HasMany
    {
        return $this->hasMany(DealStageLog::class)->orderByDesc('changed_at')->orderByDesc('id');
    }

    /**
     * @return BelongsToMany<Contact, $this, DealContact>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'deal_contacts')
            ->using(DealContact::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Competitor, $this, DealCompetitor>
     */
    public function competitors(): BelongsToMany
    {
        return $this->belongsToMany(Competitor::class, 'deal_competitors')
            ->using(DealCompetitor::class)
            ->withPivot('is_winner', 'notes')
            ->withTimestamps();
    }

    /**
     * @return HasMany<DealProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(DealProduct::class)->orderBy('sort')->orderBy('id');
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
     * Tasks linked to the deal, soonest due first (decision A-10).
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('due_at')->orderBy('id');
    }

    public function isClosed(): bool
    {
        return $this->status !== DealStatus::Open;
    }

    public function isWon(): bool
    {
        return $this->status === DealStatus::Won;
    }

    public function isLost(): bool
    {
        return $this->status === DealStatus::Lost;
    }
}
