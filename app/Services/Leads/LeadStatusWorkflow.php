<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Enums\ActivityLogEvent;
use App\Enums\LeadStatusKind;
use App\Exceptions\Leads\InvalidLeadTransitionException;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeadStatusLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The only way a lead's status changes (decision D-7).
 *
 * - a converted lead is frozen;
 * - the Converted status is reserved for LeadConversionWorkflow;
 * - entering a status of kind Qualified requires a note and stamps
 *   qualified_at / qualified_by;
 * - every change writes a lead_status_logs row and an audit event, inside one
 *   transaction with the row locked.
 */
final class LeadStatusWorkflow
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LeadScoringService $scoring,
    ) {}

    public function transition(Lead $lead, LeadStatus $to, User $actor, ?string $note = null): Lead
    {
        $note = trim((string) $note);

        return DB::transaction(function () use ($lead, $to, $actor, $note): Lead {
            /** @var Lead $current */
            $current = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->firstOrFail();
            $from = $current->status;

            if ($current->isConverted()) {
                throw InvalidLeadTransitionException::alreadyConverted();
            }

            if ($to->isConverted()) {
                throw InvalidLeadTransitionException::convertedStatusReserved();
            }

            if (! $to->is_active) {
                throw InvalidLeadTransitionException::inactiveStatus();
            }

            if ((int) $current->lead_status_id === (int) $to->getKey()) {
                return $lead;
            }

            $qualifying = $to->kind === LeadStatusKind::Qualified;

            if ($qualifying && $note === '') {
                throw InvalidLeadTransitionException::qualificationNoteRequired();
            }

            Lead::withoutWorkflowGuard(function () use ($current, $to, $actor, $qualifying): void {
                $current->lead_status_id = $to->getKey();

                if ($qualifying) {
                    $current->qualified_at = now();
                    $current->qualified_by = $actor->getKey();
                }

                $current->save();
            });

            LeadStatusLog::query()->create([
                'lead_id' => $current->getKey(),
                'from_status_id' => $from?->getKey(),
                'to_status_id' => $to->getKey(),
                'changed_by' => $actor->getKey(),
                'changed_at' => now(),
                'notes' => $note === '' ? null : $note,
            ]);

            $this->audit->record($qualifying ? ActivityLogEvent::LeadQualified : ActivityLogEvent::LeadStatusChanged, $current, $actor, [
                'subject_label' => $current->full_name,
                'from_status' => $from?->getAttribute('display_name'),
                'to_status' => $to->display_name,
                'note' => $note === '' ? null : $note,
            ]);

            $this->scoring->rescore($current);

            $lead->setRawAttributes($current->getAttributes(), true);
            $lead->unsetRelation('status');

            return $lead;
        });
    }

    /**
     * Statuses the lead may move to from where it is: active, not Converted, not the current one.
     *
     * @return Builder<LeadStatus>
     */
    public function allowedTargets(Lead $lead): Builder
    {
        return LeadStatus::query()
            ->where('is_active', true)
            ->where('kind', '!=', LeadStatusKind::Converted->value)
            ->whereKeyNot($lead->lead_status_id)
            ->orderBy('sort');
    }

    public function canTransition(Lead $lead): bool
    {
        return ! $lead->isConverted();
    }
}
