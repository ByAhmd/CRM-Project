<?php

declare(strict_types=1);

namespace App\Exceptions\Settings;

use RuntimeException;

/**
 * Raised when a lead status change would break a workflow invariant
 * (decision D-7): a second Converted status, no default status, or removing
 * the status the workflow depends on. The message is already translated so
 * the page can show it to the administrator as it is.
 */
final class InvalidLeadStatusException extends RuntimeException
{
    public static function convertedAlreadyExists(): self
    {
        return new self(__('lead_statuses.validation.converted_exists'));
    }

    public static function convertedKindLocked(): self
    {
        return new self(__('lead_statuses.validation.converted_kind_locked'));
    }

    public static function convertedCannotBeDeleted(): self
    {
        return new self(__('lead_statuses.validation.converted_cannot_be_deleted'));
    }

    public static function defaultCannotBeDeactivated(): self
    {
        return new self(__('lead_statuses.validation.default_cannot_be_deactivated'));
    }

    public static function defaultCannotBeUnset(): self
    {
        return new self(__('lead_statuses.validation.default_cannot_be_unset'));
    }

    public static function defaultCannotBeDeleted(): self
    {
        return new self(__('lead_statuses.validation.default_cannot_be_deleted'));
    }
}
