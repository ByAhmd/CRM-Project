<?php

declare(strict_types=1);

namespace App\Exceptions\Settings;

use App\Enums\StageKind;
use RuntimeException;

/**
 * Raised when a pipeline or stage change would break a workflow invariant
 * (decision D-8): no default pipeline, a deactivated or deleted default, a
 * pipeline or stage still referenced by deals or stage history, or a stage
 * set without an Open stage, without exactly one Won and one Lost, or
 * without exactly one default Open stage. The message is already
 * translated so the page can show it to the administrator as it is.
 */
final class InvalidPipelineException extends RuntimeException
{
    public static function defaultCannotBeUnset(): self
    {
        return new self(__('pipelines.validation.default_cannot_be_unset'));
    }

    public static function defaultCannotBeDeactivated(): self
    {
        return new self(__('pipelines.validation.default_cannot_be_deactivated'));
    }

    public static function defaultCannotBeDeleted(): self
    {
        return new self(__('pipelines.validation.default_cannot_be_deleted'));
    }

    public static function holdsDeals(): self
    {
        return new self(__('pipelines.validation.in_use'));
    }

    public static function deletedCannotBeDefault(): self
    {
        return new self(__('pipelines.validation.deleted_cannot_be_default'));
    }

    public static function openStageRequired(): self
    {
        return new self(__('pipelines.stages.validation.open_required'));
    }

    public static function wonStageRequired(): self
    {
        return new self(__('pipelines.stages.validation.won_exactly_one'));
    }

    public static function lostStageRequired(): self
    {
        return new self(__('pipelines.stages.validation.lost_exactly_one'));
    }

    public static function defaultStageRequired(): self
    {
        return new self(__('pipelines.stages.validation.default_exactly_one'));
    }

    public static function defaultStageMustBeOpen(): self
    {
        return new self(__('pipelines.stages.validation.default_must_be_open'));
    }

    public static function lastStageOfKind(StageKind $kind): self
    {
        return new self(__('pipelines.stages.validation.last_of_kind', ['kind' => $kind->getLabel()]));
    }

    public static function stageInUse(): self
    {
        return new self(__('pipelines.stages.validation.in_use'));
    }

    public static function probabilityOutOfRange(): self
    {
        return new self(__('pipelines.stages.validation.probability_range'));
    }
}
