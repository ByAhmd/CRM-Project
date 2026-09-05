<?php

declare(strict_types=1);

namespace App\Exceptions\Deals;

use RuntimeException;

/**
 * A deal stage change the workflow refuses (decision D-8).
 */
final class InvalidDealTransitionException extends RuntimeException
{
    public static function stageOutsidePipeline(): self
    {
        return new self(__('deals.validation.stage_outside_pipeline'));
    }

    public static function initialStageMustBeOpen(): self
    {
        return new self(__('deals.validation.initial_stage_must_be_open'));
    }

    public static function alreadyClosed(): self
    {
        return new self(__('deals.validation.already_closed'));
    }

    public static function notClosed(): self
    {
        return new self(__('deals.validation.not_closed'));
    }

    public static function closeReasonRequired(): self
    {
        return new self(__('deals.validation.close_reason_required'));
    }

    public static function closeReasonKindMismatch(): self
    {
        return new self(__('deals.validation.close_reason_kind_mismatch'));
    }

    public static function pipelineHasNoClosedStage(): self
    {
        return new self(__('deals.validation.pipeline_has_no_closed_stage'));
    }

    public static function pipelineHasNoOpenStage(): self
    {
        return new self(__('deals.validation.pipeline_has_no_open_stage'));
    }
}
