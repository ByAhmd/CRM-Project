<?php

declare(strict_types=1);

namespace App\Exceptions\Leads;

use RuntimeException;

/**
 * A lead status change the workflow refuses (decision D-7).
 */
final class InvalidLeadTransitionException extends RuntimeException
{
    public static function alreadyConverted(): self
    {
        return new self(__('leads.validation.already_converted'));
    }

    public static function inactiveStatus(): self
    {
        return new self(__('leads.validation.inactive_status'));
    }

    public static function convertedStatusReserved(): self
    {
        return new self(__('leads.validation.converted_status_reserved'));
    }

    public static function qualificationNoteRequired(): self
    {
        return new self(__('leads.validation.qualification_note_required'));
    }

    public static function notQualified(): self
    {
        return new self(__('leads.validation.not_qualified'));
    }

    public static function convertedStatusMissing(): self
    {
        return new self(__('leads.validation.converted_status_missing'));
    }

    public static function accountNameRequired(): self
    {
        return new self(__('leads.validation.account_name_required'));
    }

    public static function accountNotAccessible(): self
    {
        return new self(__('leads.validation.account_not_accessible'));
    }

    public static function contactNotAccessible(): self
    {
        return new self(__('leads.validation.contact_not_accessible'));
    }

    public static function pipelineHasNoDefaultStage(): self
    {
        return new self(__('leads.validation.pipeline_has_no_default_stage'));
    }

    public static function pipelineInactive(): self
    {
        return new self(__('leads.validation.pipeline_inactive'));
    }
}
