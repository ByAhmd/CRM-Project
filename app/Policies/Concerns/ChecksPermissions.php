<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Contracts\OwnedRecord;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Shared permission + scope lookups for policies over owned records (D-3, D-4).
 *
 * Two independent checks: the permission answers "may this role do this to
 * this entity at all", RecordVisibilityResolver answers "to this record".
 * Both must pass.
 *
 * The bulk abilities are defined explicitly. Filament's DeleteBulkAction,
 * RestoreBulkAction and ForceDeleteBulkAction authorise against deleteAny /
 * restoreAny / forceDeleteAny, and when a policy exists but lacks the method,
 * Filament's authorisation helper falls through to *allow* — so an absent
 * method would hand every role a bulk delete. Each defers to its singular
 * form; per-record scope is enforced by authorizeIndividualRecords() on the
 * bulk actions themselves.
 *
 * Permanent deletion is never granted (D-13): soft-deleted records are kept
 * and restorable.
 *
 * A soft-deleted record is frozen until it is restored (D-13): `update` and
 * every custom verb refuse it, as AttachmentPolicy and NotePolicy refuse new
 * files and notes on it. Restoring is the one write it accepts, so the
 * resources can still bind trashed records for the restore action.
 */
trait ChecksPermissions
{
    abstract protected function permissionGroup(): string;

    public function viewAny(User $user): bool
    {
        return $user->can($this->permission('view_any'));
    }

    public function view(User $user, Model&OwnedRecord $record): bool
    {
        return $this->viewAny($user) && $this->resolver()->canRead($user, $record);
    }

    public function create(User $user): bool
    {
        return $user->can($this->permission('create'));
    }

    public function update(User $user, (Model&OwnedRecord)|null $record = null): bool
    {
        return ! $this->isTrashed($record)
            && $user->can($this->permission('update'))
            && $this->reaches($user, $record);
    }

    public function delete(User $user, (Model&OwnedRecord)|null $record = null): bool
    {
        return $user->can($this->permission('delete')) && $this->reaches($user, $record);
    }

    public function restore(User $user, (Model&OwnedRecord)|null $record = null): bool
    {
        return $user->can($this->permission('restore')) && $this->reaches($user, $record);
    }

    public function forceDelete(User $user, (Model&OwnedRecord)|null $record = null): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return $this->delete($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->restore($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    /** A custom verb (`assign`, `convert`, `change_stage`, …) on a specific record. */
    protected function verb(User $user, string $verb, (Model&OwnedRecord)|null $record = null): bool
    {
        return ! $this->isTrashed($record) && $user->can($this->permission($verb)) && $this->reaches($user, $record);
    }

    /** Whether the record is soft-deleted, and so frozen until it is restored. */
    protected function isTrashed(?Model $record): bool
    {
        return $record !== null
            && in_array(SoftDeletes::class, class_uses_recursive($record), true)
            && method_exists($record, 'trashed')
            && $record->trashed() === true;
    }

    protected function permission(string $verb): string
    {
        return $this->permissionGroup().'.'.$verb;
    }

    protected function reaches(User $user, (Model&OwnedRecord)|null $record): bool
    {
        return $record === null || $this->resolver()->canWrite($user, $record);
    }

    protected function resolver(): RecordVisibilityResolver
    {
        return app(RecordVisibilityResolver::class);
    }
}
