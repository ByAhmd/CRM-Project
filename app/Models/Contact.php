<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\OwnedRecord;
use App\Enums\ActivityLogEvent;
use App\Enums\CustomFieldEntity;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\HasNormalizedContactColumns;
use App\Models\Concerns\HasOwner;
use App\Models\Concerns\HasTags;
use Database\Factories\ContactFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A person, belonging to at most one account (D-6).
 *
 * @property bool $is_primary
 * @property-read string $full_name
 */
#[Fillable([
    'account_id', 'lead_id', 'first_name', 'last_name', 'job_title', 'department', 'email', 'phone', 'mobile',
    'preferred_locale', 'linkedin_url', 'address_line', 'city', 'region', 'country', 'postal_code',
    'is_primary', 'owner_id', 'description', 'created_by',
])]
final class Contact extends Model implements HasLocalePreference, OwnedRecord
{
    use HasAttachments;
    use HasCustomFields;

    /** @use HasFactory<ContactFactory> */
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
        return 'contact';
    }

    /** The custom field definitions this model carries (D-9). */
    public static function customFieldEntity(): CustomFieldEntity
    {
        return CustomFieldEntity::Contact;
    }

    /**
     * @return list<string>
     */
    public static function phoneSourceColumns(): array
    {
        return ['mobile', 'phone'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(ActivityLogEvent::ContactUpdated->logName())
            ->logOnly([
                'account_id', 'first_name', 'last_name', 'job_title', 'department', 'email', 'phone', 'mobile',
                'preferred_locale', 'is_primary', 'owner_id',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created' => ActivityLogEvent::ContactCreated->value,
            'deleted' => ActivityLogEvent::ContactDeleted->value,
            'restored' => ActivityLogEvent::ContactRestored->value,
            default => ActivityLogEvent::ContactUpdated->value,
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
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Activities logged against the contact, newest first (decision A-10).
     *
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * The lead this contact was converted from, if any (D-7).
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Deals this contact takes part in, with their role (D-6).
     *
     * @return BelongsToMany<Deal, $this, DealContact>
     */
    public function deals(): BelongsToMany
    {
        return $this->belongsToMany(Deal::class, 'deal_contacts')
            ->using(DealContact::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Deals where this contact is the primary contact.
     *
     * @return HasMany<Deal, $this>
     */
    public function primaryDeals(): HasMany
    {
        return $this->hasMany(Deal::class, 'contact_id');
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
     * Tasks linked to the contact, soonest due first (decision A-10).
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('due_at')->orderBy('id');
    }

    /** Outbound mail to this contact is rendered in their language (D-5, D-10). */
    public function preferredLocale(): string
    {
        $locale = $this->preferred_locale;

        if (is_string($locale) && in_array($locale, (array) config('app.locales'), true)) {
            return $locale;
        }

        return (string) config('app.locale');
    }
}
