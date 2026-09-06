<?php

declare(strict_types=1);

namespace App\Exceptions\Attachments;

use RuntimeException;

/**
 * An upload AttachmentStorage refuses (module row 13): a MIME type outside
 * the allowlist, a file over the size limit, or a temporary file that is no
 * longer on the disk.
 */
final class InvalidAttachmentException extends RuntimeException
{
    public static function mimeNotAllowed(string $mime): self
    {
        return new self(__('attachments.validation.mime_not_allowed', ['mime' => $mime]));
    }

    public static function tooLarge(string $max): self
    {
        return new self(__('attachments.validation.too_large', ['max' => $max]));
    }

    public static function fileMissing(): self
    {
        return new self(__('attachments.validation.file_missing'));
    }

    public static function storageFailed(): self
    {
        return new self(__('attachments.validation.storage_failed'));
    }
}
