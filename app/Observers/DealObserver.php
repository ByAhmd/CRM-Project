<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\DealStatus;
use App\Enums\StageKind;
use App\Exceptions\Deals\InvalidDealTransitionException;
use App\Models\Deal;
use App\Models\PipelineStage;

/**
 * Integrity guard for a new deal (decisions D-6, D-8).
 *
 * GuardsWorkflowFields protects the workflow columns on update only, so this
 * observer covers creation: the initial stage must belong to the deal's
 * pipeline and be an Open stage, the status is derived from it and the close
 * columns start empty. Winning or losing a deal — and everything that hangs
 * off it (won_at, close reason, stage log, audit, account promotion) — goes
 * through DealStageWorkflow only.
 */
final class DealObserver
{
    public function creating(Deal $deal): void
    {
        $stage = PipelineStage::query()->find($deal->stage_id);

        if ($stage !== null) {
            if ((int) $stage->pipeline_id !== (int) $deal->pipeline_id) {
                throw InvalidDealTransitionException::stageOutsidePipeline();
            }

            if ($stage->kind !== StageKind::Open) {
                throw InvalidDealTransitionException::initialStageMustBeOpen();
            }
        }

        $deal->status = DealStatus::Open;
        $deal->won_at = null;
        $deal->lost_at = null;
        $deal->close_reason_id = null;
        $deal->lost_notes = null;
    }
}
