<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\LeadScoringRuleKind;
use App\Enums\LeadStatusKind;
use App\Exceptions\Settings\InvalidLeadStatusException;
use App\Models\Lead;
use App\Models\LeadScoringRule;
use App\Models\LeadStatus;
use App\Models\LeadStatusLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The only write path for lead statuses (decisions D-7, A-4).
 *
 * Two invariants hold at every moment:
 *
 * 1. Exactly one status is the default for new leads. Saving a row as the
 *    default clears the flag on every other row inside the same transaction;
 *    the default can be neither unset, deactivated nor deleted — an
 *    administrator promotes another status instead. The first status ever
 *    created becomes the default so the invariant holds from the first row.
 * 2. Exactly one status is of kind Converted (LeadStatusKind::isSingleton()).
 *    A second Converted row is refused, the Converted row keeps its kind and
 *    cannot be deleted.
 *
 * A status that is referenced — by a lead (soft-deleted ones keep their
 * foreign key), by either side of a status-history row or by a scoring rule —
 * cannot be deleted either: the RESTRICT keys would refuse it, so the service
 * refuses first with a message that points to deactivation.
 *
 * MySQL has no partial unique index that could back either invariant, so
 * every existence check that decides a write takes a FOR UPDATE lock on the
 * rows it inspects; two concurrent saves are serialised inside the same
 * transaction instead of both passing the check.
 *
 * Every refusal is an InvalidLeadStatusException carrying a translated
 * message; the pages show it as a danger notification and halt.
 */
final class LeadStatusService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): LeadStatus
    {
        return DB::transaction(function () use ($data): LeadStatus {
            $kind = $this->kindFrom($data['kind'] ?? null);

            if ($kind?->isSingleton() === true && $this->singletonQuery($kind)->exists()) {
                throw InvalidLeadStatusException::convertedAlreadyExists();
            }

            $wantsDefault = (bool) ($data['is_default'] ?? false);

            if (! $wantsDefault && ! $this->defaultQuery()->exists()) {
                $data['is_default'] = true;
                $wantsDefault = true;
            }

            if ($wantsDefault && ! (bool) ($data['is_active'] ?? true)) {
                throw InvalidLeadStatusException::defaultCannotBeDeactivated();
            }

            if ($wantsDefault) {
                $this->clearDefaults();
            }

            return LeadStatus::query()->create($data);
        });
    }

    /**
     * The instance the caller holds is the one that is saved, so a page that
     * keeps its record after saving reads the persisted attributes.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(LeadStatus $status, array $data): LeadStatus
    {
        return DB::transaction(function () use ($status, $data): LeadStatus {
            $current = LeadStatus::query()->whereKey($status->getKey())->lockForUpdate()->firstOrFail();
            $kind = $this->kindFrom($data['kind'] ?? null) ?? $current->kind;

            if ($current->isConverted() && $kind !== LeadStatusKind::Converted) {
                throw InvalidLeadStatusException::convertedKindLocked();
            }

            if ($kind->isSingleton() && ! $current->isConverted() && $this->singletonQuery($kind)->whereKeyNot($current->getKey())->exists()) {
                throw InvalidLeadStatusException::convertedAlreadyExists();
            }

            $wantsDefault = array_key_exists('is_default', $data) ? (bool) $data['is_default'] : $current->isDefault();
            $wantsActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $current->is_active;

            if ($current->isDefault() && ! $wantsDefault) {
                throw InvalidLeadStatusException::defaultCannotBeUnset();
            }

            if ($wantsDefault && ! $wantsActive) {
                throw InvalidLeadStatusException::defaultCannotBeDeactivated();
            }

            if ($wantsDefault && ! $current->isDefault()) {
                $this->clearDefaults($current);
            }

            $status->setRawAttributes($current->getAttributes(), true);
            $status->update($data);

            return $status;
        });
    }

    public function delete(LeadStatus $status): void
    {
        DB::transaction(function () use ($status): void {
            $current = LeadStatus::query()->whereKey($status->getKey())->lockForUpdate()->firstOrFail();

            if ($current->isDefault()) {
                throw InvalidLeadStatusException::defaultCannotBeDeleted();
            }

            if ($current->isConverted()) {
                throw InvalidLeadStatusException::convertedCannotBeDeleted();
            }

            if ($this->isInUse($current)) {
                throw InvalidLeadStatusException::inUse();
            }

            $current->delete();
        });
    }

    /** Whether the record may be removed at all — the pages hide the action when it may not. */
    public function isDeletable(LeadStatus $status): bool
    {
        return ! $status->isDefault() && ! $status->isConverted() && ! $this->isInUse($status);
    }

    /** Whether a lead, a status-history row or a scoring rule still references the status. */
    public function isInUse(LeadStatus $status): bool
    {
        $id = $status->getKey();

        return Lead::withTrashed()->where('lead_status_id', $id)->exists()
            || LeadStatusLog::query()->where(fn (Builder $query): Builder => $query->where('to_status_id', $id)->orWhere('from_status_id', $id))->exists()
            || LeadScoringRule::query()->where('kind', LeadScoringRuleKind::Status->value)->where('reference_id', $id)->exists();
    }

    /**
     * Each row is saved through Eloquent, not a bulk query, so the audit
     * ledger records the flag leaving the previous default.
     */
    private function clearDefaults(?LeadStatus $except = null): void
    {
        $defaults = $this->defaultQuery();

        if ($except !== null) {
            $defaults->whereKeyNot($except->getKey());
        }

        $defaults->get()->each(static fn (LeadStatus $other): bool => $other->update(['is_default' => false]));
    }

    /**
     * @return Builder<LeadStatus>
     */
    private function defaultQuery(): Builder
    {
        return LeadStatus::query()->where('is_default', true)->lockForUpdate();
    }

    /**
     * @return Builder<LeadStatus>
     */
    private function singletonQuery(LeadStatusKind $kind): Builder
    {
        return LeadStatus::query()->where('kind', $kind->value)->lockForUpdate();
    }

    private function kindFrom(mixed $value): ?LeadStatusKind
    {
        if ($value instanceof LeadStatusKind) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return LeadStatusKind::tryFrom($value);
        }

        return null;
    }
}
