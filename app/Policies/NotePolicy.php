<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Notes (decisions D-4, A-10) have no view keys: reading follows the subject
 * record — whoever may view the lead, contact, account or deal may read the
 * notes on it. Writing needs the `note.*` permission and either authorship
 * or `update` on the subject, so a manager tidies a rep's note on a team
 * deal while a support agent edits only their own.
 *
 * The bulk abilities are explicit because Filament's authorisation helper
 * falls through to allow when a policy exists but lacks the method.
 * Permanent deletion is never granted (D-13).
 */
final class NotePolicy
{
    /** The relation manager is gated by the subject's `view`; the list itself is open. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Note $note): bool
    {
        $subject = $note->subjectRecord();

        return $subject !== null && $user->can('view', $subject);
    }

    /**
     * Called by the relation manager as `can('create', [Note::class, $ownerRecord])`;
     * without a subject only the permission is checked.
     */
    public function create(User $user, ?Model $subject = null): bool
    {
        if (! $user->can(Permission::NoteCreate->value)) {
            return false;
        }

        return $subject === null || $user->can('view', $subject);
    }

    public function update(User $user, Note $note): bool
    {
        return $user->can(Permission::NoteUpdate->value) && $this->authorsOrUpdatesSubject($user, $note);
    }

    public function pin(User $user, Note $note): bool
    {
        return $this->update($user, $note);
    }

    public function delete(User $user, Note $note): bool
    {
        return $user->can(Permission::NoteDelete->value) && $this->authorsOrUpdatesSubject($user, $note);
    }

    public function restore(User $user, Note $note): bool
    {
        return $this->delete($user, $note);
    }

    public function forceDelete(User $user, Note $note): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(Permission::NoteDelete->value);
    }

    public function restoreAny(User $user): bool
    {
        return $this->deleteAny($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    private function authorsOrUpdatesSubject(User $user, Note $note): bool
    {
        if ($note->isAuthoredBy($user)) {
            return true;
        }

        $subject = $note->subjectRecord();

        return $subject !== null && $user->can('update', $subject);
    }
}
