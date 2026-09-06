<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Integrity guards for attachments (module row 13, decision D-13).
 *
 * Every row gets its uuid before insert so the download key never depends on
 * the caller. A soft delete keeps the file (D-13: deleted records stay
 * restorable); only a force delete removes it from the disk, after the row
 * is gone so a failed delete never leaves a row pointing at nothing.
 */
final class AttachmentObserver
{
    public function creating(Attachment $attachment): void
    {
        if (blank($attachment->uuid)) {
            $attachment->uuid = (string) Str::uuid();
        }
    }

    public function forceDeleted(Attachment $attachment): void
    {
        Storage::disk($attachment->disk)->delete($attachment->path);
    }
}
