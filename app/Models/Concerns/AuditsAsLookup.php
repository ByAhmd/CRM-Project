<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\ActivityLogEvent;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Audit instrumentation shared by every configurable lookup (decision A-5).
 *
 * Lookups are settings data: every create, update, delete and restore is
 * written to the `settings` channel of the ledger with the attributes the
 * model names in auditedAttributes().
 */
trait AuditsAsLookup
{
    use LogsActivity;

    /**
     * @return list<string>
     */
    abstract public static function auditedAttributes(): array;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(ActivityLogEvent::LookupUpdated->logName())
            ->logOnly(static::auditedAttributes())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created' => ActivityLogEvent::LookupCreated->value,
            'deleted' => ActivityLogEvent::LookupDeleted->value,
            'restored' => ActivityLogEvent::LookupRestored->value,
            default => ActivityLogEvent::LookupUpdated->value,
        };
    }
}
