<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Enums\LeadStatusKind;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\LeadStaleNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The daily "your lead has gone quiet" pass (plan section 3.6, decision D-1).
 *
 * An open lead — not converted, not in a terminal status, with an owner —
 * whose last activity (or, failing any, its creation) is older than
 * crm.leads.stale_days is reported to its owner once: `stale_notified_at` is
 * stamped quietly, outside the workflow guard, because it is bookkeeping
 * rather than a business change. ActivityRecorder clears the stamp whenever
 * a new activity moves last_activity_at forward, so a lead that goes quiet
 * again after being worked is reported again. A send that fails (a broken
 * mailbox) is reported and does not stall the pass: the in-app entry is
 * written before mail is attempted, so the lead is stamped anyway.
 */
final class LeadStaleService
{
    private const int CHUNK = 100;

    /**
     * Notifies the owner of every stale lead not yet reported and returns
     * how many were sent.
     */
    public function notify(Carbon $now): int
    {
        $days = self::staleDays();
        $threshold = $now->copy()->subDays($days);
        $count = 0;

        $this->candidates($threshold)->chunkById(self::CHUNK, function (Collection $leads) use ($now, &$count): void {
            foreach ($leads as $lead) {
                $owner = $lead->owner;

                if (! $owner instanceof User) {
                    continue;
                }

                // An owner who may no longer sign in (disabled, pending) is not
                // told; the lead is still stamped so the pass stays idempotent.
                if (! $owner->status->canAuthenticate()) {
                    Lead::withoutWorkflowGuard(static fn (): bool => $lead->forceFill(['stale_notified_at' => $now])->saveQuietly());

                    continue;
                }

                try {
                    $owner->notify((new LeadStaleNotification($lead, $this->daysIdle($lead, $now)))->locale($owner->preferredLocale()));
                } catch (Throwable $exception) {
                    // The notification is queued, so a failure here means nothing
                    // was delivered at all: the lead stays unstamped and the next
                    // pass tries again; the failure is reported and the pass carries on.
                    report($exception);

                    continue;
                }

                Lead::withoutWorkflowGuard(static fn (): bool => $lead->forceFill(['stale_notified_at' => $now])->saveQuietly());

                $count++;
            }
        });

        return $count;
    }

    public static function staleDays(): int
    {
        return max(1, (int) config('crm.leads.stale_days'));
    }

    /**
     * Open, owned leads not yet reported whose last activity — or creation —
     * is older than the threshold.
     *
     * @return Builder<Lead>
     */
    private function candidates(Carbon $threshold): Builder
    {
        $terminalKinds = array_values(array_map(
            static fn (LeadStatusKind $kind): string => $kind->value,
            array_filter(LeadStatusKind::cases(), static fn (LeadStatusKind $kind): bool => $kind->isTerminal()),
        ));

        return Lead::query()
            ->whereNull('converted_at')
            ->whereNotNull('owner_id')
            ->whereNull('stale_notified_at')
            ->whereHas('status', static fn (Builder $status): Builder => $status->whereNotIn('kind', $terminalKinds))
            ->whereRaw('COALESCE(last_activity_at, created_at) < ?', [$threshold])
            ->with('owner');
    }

    /** Whole days since the last activity, or since creation when there is none. */
    private function daysIdle(Lead $lead, Carbon $now): int
    {
        $since = $lead->last_activity_at ?? $lead->created_at;

        if ($since === null) {
            return self::staleDays();
        }

        return max(0, (int) floor($since->diffInDays($now)));
    }
}
