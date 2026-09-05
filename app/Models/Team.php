<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityLogEvent;
use App\Models\Concerns\HasLocalisedName;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A sales team (decision D-4).
 *
 * @property bool $is_active
 * @property-read string $display_name
 */
#[Fillable(['name_ar', 'name_en', 'manager_user_id', 'is_active', 'sort'])]
final class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    use HasLocalisedName;
    use LogsActivity;
    use SoftDeletes;

    /** @var list<string> */
    protected static array $recordEvents = ['created', 'updated', 'deleted', 'restored'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(ActivityLogEvent::TeamUpdated->logName())
            ->logOnly(['name_ar', 'name_en', 'manager_user_id', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created' => ActivityLogEvent::TeamCreated->value,
            'deleted' => ActivityLogEvent::TeamDeleted->value,
            'restored' => ActivityLogEvent::TeamRestored->value,
            default => ActivityLogEvent::TeamUpdated->value,
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
