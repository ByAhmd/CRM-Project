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
}
