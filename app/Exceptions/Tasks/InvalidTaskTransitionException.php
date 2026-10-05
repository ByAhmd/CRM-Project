<?php

declare(strict_types=1);

namespace App\Exceptions\Tasks;

use RuntimeException;

/**
 * A task status change — or a comment, or a checklist tick — the service
 * refuses (decisions A-10, D-17, D-25).
 */
final class InvalidTaskTransitionException extends RuntimeException
{
    public static function notOpen(): self
    {
        return new self(__('tasks.validation.not_open'));
    }

    public static function notPending(): self
    {
        return new self(__('tasks.validation.not_pending'));
    }

    public static function notOpenForUpdate(): self
    {
        return new self(__('tasks.validation.update_not_open'));
    }

    public static function notClosed(): self
    {
        return new self(__('tasks.validation.not_closed'));
    }

    public static function trashed(): self
    {
        return new self(__('tasks.validation.trashed'));
    }

    /** A deleted task takes no comments until it is restored (D-13, D-17). */
    public static function commentOnTrashed(): self
    {
        return new self(__('tasks.validation.comment_trashed'));
    }

    /** A completed, cancelled or deleted task's checklist is frozen (D-25). */
    public static function checklistNotOpen(): self
    {
        return new self(__('tasks.validation.checklist_not_open'));
    }
}
