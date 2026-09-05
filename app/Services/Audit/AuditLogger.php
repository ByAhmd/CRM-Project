<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\ActivityLogEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes business events to the audit ledger (decision A-5).
 *
 * Model attribute changes are recorded automatically by LogsActivity on each
 * audited model; this class is for events that are not attribute changes —
 * a login, a role grant, a conversion. Every write names an ActivityLogEvent
 * so the ledger never contains free-text descriptions.
 *
 * The properties array is data for the audit screen: ids, before/after
 * values, labels. Secrets never belong here.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        ActivityLogEvent $event,
        ?Model $subject = null,
        ?User $causer = null,
        array $properties = [],
    ): void {
        $causer ??= $this->currentUser();

        $log = activity($event->logName())
            ->event($event->value)
            ->withProperties($properties);

        if ($subject !== null) {
            $log->performedOn($subject);
        }

        if ($causer !== null) {
            $log->causedBy($causer);
        }

        $log->log($event->value);
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
