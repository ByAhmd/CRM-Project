<?php

declare(strict_types=1);

namespace App\Services\Deals;

use App\Enums\ActivityLogEvent;
use App\Enums\CloseReasonKind;
use App\Enums\DealStatus;
use App\Enums\StageKind;
use App\Exceptions\Deals\InvalidDealTransitionException;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\DealStageLog;
use App\Models\PipelineStage;
use App\Models\User;
use App\Notifications\DealClosedNotification;
use App\Notifications\DealStageChangedNotification;
use App\Services\Accounts\AccountLifecycleService;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationRecipients;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only way a deal's stage changes (decisions D-6, D-8).
 *
 * - a stage must belong to the deal's pipeline;
 * - a closed deal is frozen: it changes only through reopen();
 * - entering a Won or Lost stage requires a close reason of the matching
 *   kind, derives the status, stamps won_at / lost_at and the reason;
 * - winning promotes a prospect account to customer (D-6);
 * - every change writes a deal_stage_logs row (with the seconds spent in the
 *   previous stage) and an audit event, inside one transaction with the row
 *   locked;
 * - once the transaction has committed, the owner hears about a move made by
 *   someone else and the owner's team manager about a win or a loss (plan
 *   section 3.6); the actor is never told about their own action.
 */
final class DealStageWorkflow
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccountLifecycleService $accounts,
        private readonly NotificationRecipients $recipients,
    ) {}

    public function transition(
        Deal $deal,
        PipelineStage $to,
        User $actor,
        ?string $note = null,
        ?DealCloseReason $reason = null,
        ?string $lostNotes = null,
    ): Deal {
        $note = trim((string) $note);
        $lostNotes = trim((string) $lostNotes);

        return DB::transaction(function () use ($deal, $to, $actor, $note, $reason, $lostNotes): Deal {
            /** @var Deal $current */
            $current = Deal::query()->whereKey($deal->getKey())->lockForUpdate()->firstOrFail();
            $from = $current->stage;

            if ((int) $to->pipeline_id !== (int) $current->pipeline_id) {
                throw InvalidDealTransitionException::stageOutsidePipeline();
            }

            if ($current->isClosed()) {
                throw InvalidDealTransitionException::alreadyClosed();
            }

            if ((int) $current->stage_id === (int) $to->getKey()) {
                return $deal;
            }

            $kind = $to->kind;
            $closeReasonId = null;
            $closeReasonLabel = null;

            if ($kind !== StageKind::Open) {
                if ($reason === null) {
                    throw InvalidDealTransitionException::closeReasonRequired();
                }

                $expected = $kind === StageKind::Won ? CloseReasonKind::Won : CloseReasonKind::Lost;

                if ($reason->kind !== $expected) {
                    throw InvalidDealTransitionException::closeReasonKindMismatch();
                }

                $closeReasonId = (int) $reason->getKey();
                $closeReasonLabel = $reason->display_name;
            }

            $now = now();

            Deal::withoutWorkflowGuard(function () use ($current, $to, $kind, $closeReasonId, $lostNotes, $now): void {
                $current->stage_id = $to->getKey();
                $current->status = DealStatus::fromStageKind($kind);

                if ($kind === StageKind::Won) {
                    $current->won_at = $now;
                    $current->lost_at = null;
                    $current->lost_notes = null;
                    $current->close_reason_id = $closeReasonId;
                }

                if ($kind === StageKind::Lost) {
                    $current->lost_at = $now;
                    $current->lost_notes = $lostNotes === '' ? null : $lostNotes;
                    $current->close_reason_id = $closeReasonId;
                }

                $current->save();
            });

            $this->writeStageLog($current, $from, $to, $actor, $now, $note);

            $properties = [
                'subject_label' => $current->title,
                'from_stage' => $from?->getAttribute('display_name'),
                'to_stage' => $to->display_name,
                'note' => $note === '' ? null : $note,
            ];

            $event = match ($kind) {
                StageKind::Open => ActivityLogEvent::DealStageChanged,
                StageKind::Won => ActivityLogEvent::DealWon,
                StageKind::Lost => ActivityLogEvent::DealLost,
            };

            if ($kind === StageKind::Won) {
                $properties += [
                    'amount' => $current->amount,
                    'currency' => $current->currency,
                    'close_reason' => $closeReasonLabel,
                ];
            }

            if ($kind === StageKind::Lost) {
                $properties += [
                    'close_reason' => $closeReasonLabel,
                    'lost_notes' => $current->lost_notes,
                ];
            }

            $this->audit->record($event, $current, $actor, $properties);

            if ($kind === StageKind::Won && $current->account !== null) {
                $this->accounts->promoteToCustomer($current->account, $actor, $current);
            }

            $this->syncBack($deal, $current);

            $fromLabel = $from?->getAttribute('display_name');

            if ($kind === StageKind::Open) {
                $this->notifyStageChanged($current, is_string($fromLabel) ? $fromLabel : '', $to->display_name, $actor);
            } else {
                $this->notifyClosed($current, DealStatus::fromStageKind($kind), (string) $closeReasonLabel, $actor);
            }

            return $deal;
        });
    }

    /**
     * Reopens a won or lost deal into its pipeline's default stage and clears
     * the close columns.
     */
    public function reopen(Deal $deal, User $actor, ?string $note = null): Deal
    {
        $note = trim((string) $note);

        return DB::transaction(function () use ($deal, $actor, $note): Deal {
            /** @var Deal $current */
            $current = Deal::query()->whereKey($deal->getKey())->lockForUpdate()->firstOrFail();
            $from = $current->stage;

            if (! $current->isClosed()) {
                throw InvalidDealTransitionException::notClosed();
            }

            $to = $this->reopeningStage($current);
            $now = now();

            Deal::withoutWorkflowGuard(function () use ($current, $to): void {
                $current->stage_id = $to->getKey();
                $current->status = DealStatus::Open;
                $current->won_at = null;
                $current->lost_at = null;
                $current->lost_notes = null;
                $current->close_reason_id = null;
                $current->save();
            });

            $this->writeStageLog($current, $from, $to, $actor, $now, $note);

            $this->audit->record(ActivityLogEvent::DealReopened, $current, $actor, [
                'subject_label' => $current->title,
                'from_stage' => $from?->getAttribute('display_name'),
                'to_stage' => $to->display_name,
                'note' => $note === '' ? null : $note,
            ]);

            $this->syncBack($deal, $current);

            $fromLabel = $from?->getAttribute('display_name');

            $this->notifyStageChanged($current, is_string($fromLabel) ? $fromLabel : '', $to->display_name, $actor);

            return $deal;
        });
    }

    /**
     * Tells the owner, once the transaction has committed, that someone else
     * moved the deal. Under a sync queue the send happens right after commit.
     */
    private function notifyStageChanged(Deal $deal, string $fromStage, string $toStage, User $actor): void
    {
        $owner = $this->recipients->ownerOf($deal, $actor);

        if ($owner === null) {
            return;
        }

        DB::afterCommit(static function () use ($owner, $deal, $fromStage, $toStage, $actor): void {
            $owner->notify((new DealStageChangedNotification($deal, $fromStage, $toStage, $actor))->locale($owner->preferredLocale()));
        });
    }

    /**
     * Tells the owner and the owner's team manager, once the transaction has
     * committed, that the deal was won or lost; the actor is never told.
     */
    private function notifyClosed(Deal $deal, DealStatus $outcome, string $reason, User $actor): void
    {
        $recipients = $this->recipients->ownerAndManagerOf($deal, $actor);

        if ($recipients === []) {
            return;
        }

        DB::afterCommit(static function () use ($recipients, $deal, $outcome, $reason, $actor): void {
            foreach ($recipients as $recipient) {
                $recipient->notify((new DealClosedNotification($deal, $outcome, $reason, $actor))->locale($recipient->preferredLocale()));
            }
        });
    }

    /**
     * Open stages of the deal's pipeline the deal may move to: every Open
     * stage except the current one, in pipeline order.
     *
     * @return Builder<PipelineStage>
     */
    public function allowedStages(Deal $deal): Builder
    {
        return PipelineStage::query()
            ->where('pipeline_id', $deal->pipeline_id)
            ->where('kind', StageKind::Open->value)
            ->whereKeyNot($deal->stage_id)
            ->orderBy('sort')
            ->orderBy('id');
    }

    /**
     * The Won and Lost stages of the deal's pipeline.
     *
     * @return Builder<PipelineStage>
     */
    public function closedStages(Deal $deal): Builder
    {
        return PipelineStage::query()
            ->where('pipeline_id', $deal->pipeline_id)
            ->whereIn('kind', [StageKind::Won->value, StageKind::Lost->value])
            ->orderBy('sort')
            ->orderBy('id');
    }

    public function canTransition(Deal $deal): bool
    {
        return ! $deal->isClosed();
    }

    /**
     * The pipeline's default stage, or its first Open stage when no default
     * is flagged. Stages are read directly so a soft-deleted pipeline still
     * resolves.
     */
    private function reopeningStage(Deal $deal): PipelineStage
    {
        $stage = PipelineStage::query()
            ->where('pipeline_id', $deal->pipeline_id)
            ->where('kind', StageKind::Open->value)
            ->orderByDesc('is_default')
            ->orderBy('sort')
            ->orderBy('id')
            ->first();

        if ($stage === null) {
            throw InvalidDealTransitionException::pipelineHasNoOpenStage();
        }

        return $stage;
    }

    private function writeStageLog(Deal $deal, ?PipelineStage $from, PipelineStage $to, User $actor, Carbon $now, string $note): void
    {
        DealStageLog::query()->create([
            'deal_id' => $deal->getKey(),
            'from_stage_id' => $from?->getKey(),
            'to_stage_id' => $to->getKey(),
            'changed_by' => $actor->getKey(),
            'changed_at' => $now,
            'notes' => $note === '' ? null : $note,
            'duration_seconds' => $this->secondsInPreviousStage($deal, $now),
        ]);
    }

    /**
     * Seconds between now and the previous stage log (or the deal's creation
     * for the first change); null when the deal has no creation stamp.
     */
    private function secondsInPreviousStage(Deal $deal, Carbon $now): ?int
    {
        $previous = DealStageLog::query()
            ->where('deal_id', $deal->getKey())
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->first();

        $since = $previous !== null ? $previous->changed_at : $deal->created_at;

        if ($since === null) {
            return null;
        }

        return max(0, (int) floor($since->diffInSeconds($now)));
    }

    private function syncBack(Deal $deal, Deal $current): void
    {
        $deal->setRawAttributes($current->getAttributes(), true);
        $deal->unsetRelation('stage');
        $deal->unsetRelation('closeReason');
        $deal->unsetRelation('account');
        $deal->unsetRelation('stageLogs');
    }
}
