<?php

declare(strict_types=1);

namespace App\Exceptions\Email;

use RuntimeException;

/**
 * Raised when an email cannot be sent to the chosen record (decision D-10):
 * the recipient has no address, or the rendered subject no longer fits the
 * width of the activity it is recorded on. The message is already translated
 * so the action can show it to the user as it is.
 */
final class EmailNotSendableException extends RuntimeException
{
    public static function noEmail(): self
    {
        return new self(__('email.validation.no_email'));
    }

    public static function subjectTooLong(int $max): self
    {
        return new self(__('email.validation.subject_too_long', ['max' => $max]));
    }
}
