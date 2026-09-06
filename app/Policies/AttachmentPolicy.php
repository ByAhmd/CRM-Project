<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Attachments (module row 13, D-4, D-13): no view keys of their own — reading
 * follows the subject. A user may download a file attached to a record they
 * can view, upload against a record they can view, and delete or restore a
 * file they uploaded themselves or one attached to a record they can update.
 * Permanent deletion is never granted through the policy (D-13).
 *
 * The bulk abilities are defined explicitly for the same reason as in
 * Policies\Concerns\ChecksPermissions: Filament's authorisation helper allows
 * an ability the policy does not define.
 */
final class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $this->download($user, $attachment);
    }

    public function download(User $user, Attachment $attachment): bool
    {
        return $user->status->canAuthenticate()
            && $user->can(Permission::AttachmentDownload->value)
            && $this->canViewSubject($user, $attachment->attachable);
    }

    public function create(User $user, ?Model $attachable = null): bool
    {
        if ($attachable !== null && $this->isTrashed($attachable)) {
            return false;
        }

        return $user->can(Permission::AttachmentCreate->value)
            && ($attachable === null || $this->canViewSubject($user, $attachable));
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $user->can(Permission::AttachmentDelete->value)
            && ($this->isUploader($user, $attachment) || $this->canUpdateSubject($user, $attachment->attachable));
    }

    public function restore(User $user, Attachment $attachment): bool
    {
        return $this->delete($user, $attachment);
    }

    public function forceDelete(User $user, Attachment $attachment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(Permission::AttachmentDelete->value);
    }

    public function restoreAny(User $user): bool
    {
        return $this->deleteAny($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    /** A soft-deleted subject accepts no new files until it is restored (D-13). */
    private function isTrashed(Model $subject): bool
    {
        return method_exists($subject, 'trashed') && $subject->trashed() === true;
    }

    private function isUploader(User $user, Attachment $attachment): bool
    {
        return $attachment->uploaded_by !== null && (int) $attachment->uploaded_by === (int) $user->getKey();
    }

    /** Delegates to the subject's own policy: lead.view, deal.view, … with its record scope. */
    private function canViewSubject(User $user, ?Model $subject): bool
    {
        return $subject !== null && $user->can('view', $subject);
    }

    private function canUpdateSubject(User $user, ?Model $subject): bool
    {
        return $subject !== null && $user->can('update', $subject);
    }
}
