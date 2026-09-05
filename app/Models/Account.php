<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\OwnedRecord;
use App\Enums\AccountType;
use App\Enums\ActivityLogEvent;
use App\Enums\CompanySize;
use App\Models\Concerns\HasNormalizedContactColumns;
use App\Models\Concerns\HasOwner;
use App\Models\Concerns\HasTags;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An account: a company that is a prospect, a customer, a partner or other (D-6).
 *
 * @property AccountType $type
 * @property ?CompanySize $size
 * @property ?Carbon $customer_since
 */
#[Fillable([
    'name', 'type', 'industry_id', 'size', 'website', 'email', 'phone',
    'address_line', 'city', 'region', 'country', 'postal_code',
    'owner_id', 'parent_account_id', 'customer_since', 'description', 'created_by',
])]
final class Account extends Model implements OwnedRecord
{
    /** @use HasFactory<AccountFactory> */
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
        return 'account';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'size' => CompanySize::class,
            'customer_since' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(ActivityLogEvent::AccountUpdated->logName())
            ->logOnly([
                'name', 'type', 'industry_id', 'size', 'website', 'email', 'phone',
                'address_line', 'city', 'region', 'country', 'postal_code',
                'owner_id', 'parent_account_id', 'customer_since',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created' => ActivityLogEvent::AccountCreated->value,
            'deleted' => ActivityLogEvent::AccountDeleted->value,
            'restored' => ActivityLogEvent::AccountRestored->value,
            default => ActivityLogEvent::AccountUpdated->value,
        };
    }

    /**
     * @return BelongsTo<Industry, $this>
     */
    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_account_id');
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCustomer(): bool
    {
        return $this->type === AccountType::Customer;
    }
}
