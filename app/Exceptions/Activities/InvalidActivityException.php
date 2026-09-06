<?php

declare(strict_types=1);

namespace App\Exceptions\Activities;

use RuntimeException;

/**
 * An activity the recorder refuses to write (decision A-10).
 */
final class InvalidActivityException extends RuntimeException
{
    public static function subjectRequired(): self
    {
        return new self(__('activities.validation.subject_required'));
    }

    public static function inactiveType(): self
    {
        return new self(__('activities.validation.inactive_type'));
    }

    public static function systemTypeMissing(): self
    {
        return new self(__('activities.validation.system_type_missing'));
    }
}
