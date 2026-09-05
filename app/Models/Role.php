<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Models\Concerns\HasLocalisedName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A role with bilingual display names (decisions D-3, D-5).
 *
 * `name` is the machine key used by spatie and by code (CrmRole for the seeded
 * six); name_ar / name_en are what the interface shows. Permission changes are
 * audited by RoleService, not here — the pivot is not a model attribute.
 *
 * The roles table is created by spatie's migration under a configurable name,
 * which static analysis cannot map to columns, so the bilingual columns added
 * by this project are declared here.
 *
 * @property string $name_ar
 * @property string $name_en
 * @property-read string $display_name
 */
#[Fillable(['name', 'guard_name', 'name_ar', 'name_en'])]
final class Role extends SpatieRole
{
    use HasLocalisedName;
    use LogsActivity;

    /** @var list<string> */
    protected static array $recordEvents = ['created', 'updated', 'deleted'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(ActivityLogEvent::RoleUpdated->logName())
            ->logOnly(['name', 'name_ar', 'name_en'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created' => ActivityLogEvent::RoleCreated->value,
            'deleted' => ActivityLogEvent::RoleDeleted->value,
            default => ActivityLogEvent::RoleUpdated->value,
        };
    }

    /** One of the six seeded roles (renaming the key is refused; permissions stay editable). */
    public function isSeeded(): bool
    {
        return CrmRole::tryFrom((string) $this->name) !== null;
    }

    /** super_admin can never be edited or deleted. */
    public function isLocked(): bool
    {
        return CrmRole::tryFrom((string) $this->name)?->isLocked() ?? false;
    }
}
