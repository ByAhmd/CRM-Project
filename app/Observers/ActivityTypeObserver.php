<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ActivityType;
use LogicException;

/**
 * Integrity guard for system activity types (decision A-4).
 *
 * One system row per ActivityKind is seeded and the application relies on it
 * existing with that kind. The policy and the form keep the UI honest; this
 * observer is the last line: a kind change or a delete reaching Eloquent on
 * a system row is a bug and fails loudly.
 */
final class ActivityTypeObserver
{
    public function updating(ActivityType $type): void
    {
        if ((bool) $type->getOriginal('is_system') && $type->isDirty('kind')) {
            throw new LogicException('The kind of a system activity type cannot be changed.');
        }
    }

    public function deleting(ActivityType $type): void
    {
        if ($type->is_system) {
            throw new LogicException('A system activity type cannot be deleted.');
        }
    }
}
