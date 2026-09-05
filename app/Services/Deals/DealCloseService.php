<?php

declare(strict_types=1);

namespace App\Services\Deals;

use App\Enums\StageKind;
use App\Exceptions\Deals\InvalidDealTransitionException;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\PipelineStage;
use App\Models\User;

/**
 * Win, lose and reopen a deal (decisions D-6, D-8): the explicit API the
 * pages and later the reports call. Each verb resolves the pipeline's Won or
 * Lost stage and delegates to DealStageWorkflow, which owns every rule.
 */
final class DealCloseService
{
    public function __construct(
        private readonly DealStageWorkflow $workflow,
    ) {}

    public function win(Deal $deal, DealCloseReason $reason, User $actor, ?string $note = null): Deal
    {
        return $this->workflow->transition($deal, $this->closedStage($deal, StageKind::Won), $actor, $note, $reason);
    }

    public function lose(Deal $deal, DealCloseReason $reason, User $actor, ?string $lostNotes = null): Deal
    {
        return $this->workflow->transition($deal, $this->closedStage($deal, StageKind::Lost), $actor, null, $reason, $lostNotes);
    }

    public function reopen(Deal $deal, User $actor, ?string $note = null): Deal
    {
        return $this->workflow->reopen($deal, $actor, $note);
    }

    /**
     * The pipeline's Won or Lost stage, read directly from the stages so a
     * soft-deleted pipeline still resolves.
     */
    private function closedStage(Deal $deal, StageKind $kind): PipelineStage
    {
        $stage = PipelineStage::query()
            ->where('pipeline_id', $deal->pipeline_id)
            ->where('kind', $kind->value)
            ->orderBy('sort')
            ->orderBy('id')
            ->first();

        if ($stage === null) {
            throw InvalidDealTransitionException::pipelineHasNoClosedStage();
        }

        return $stage;
    }
}
