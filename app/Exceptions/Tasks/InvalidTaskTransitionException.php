<?php

declare(strict_types=1);

namespace App\Exceptions\Tasks;

use RuntimeException;

/**
 * A task status change the service refuses (decision A-10).
 */
final class InvalidTaskTransitionException extends RuntimeException
{
    public static function notOpen(): self
    {
        return new self(__('tasks.validation.not_open'));
    }

    public static function notClosed(): self
    {
        return new self(__('tasks.validation.not_closed'));
    }

    public static function trashed(): self
    {
        return new self(__('tasks.validation.trashed'));
    }
}
