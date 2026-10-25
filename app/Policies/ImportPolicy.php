<?php

declare(strict_types=1);

namespace App\Policies;

use App\Contracts\OwnedRecord;
use App\Enums\Permission;
use App\Models\User;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

/**
 * Import history (module 18, decisions D-4, D-13).
 *
 * A run's failed rows are the raw rows the importer uploaded, whoever they
 * name as owner, so reaching them must never widen the reader's scope: a user
 * always sees their own runs; `imports.view` opens another user's run only
 * when the holder reaches every record of the run's entity
 * (`{entity}.view_all`).
 *
 * An account that may no longer sign in (disabled, pending) reaches nothing,
 * even with a live session: Filament's failed-rows download route runs
 * outside the panel middleware, so the status is checked here.
 *
 * The policy is registered for Filament's base Import model as well
 * (AppServiceProvider), because Filament's download route binds that class
 * rather than App\Models\Import. Rows are written by Filament's ImportAction,
 * by the ImportStarted listener (`dry_run`, D-29) and by ImportUndoService
 * (the undo stamps and counts, D-29); nobody edits or removes one by hand —
 * retention pruning is the only way one disappears.
 */
final class ImportPolicy
{
    /**
     * @var list<Permission>
     */
    private const IMPORT_PERMISSIONS = [
        Permission::LeadImport,
        Permission::ContactImport,
        Permission::AccountImport,
        Permission::DealImport,
    ];

    /** Anyone who may import something, or who may review runs. */
    public function viewAny(User $user): bool
    {
        if (! $user->status->canAuthenticate()) {
            return false;
        }

        if ($user->can(Permission::ImportsView->value)) {
            return true;
        }

        foreach (self::IMPORT_PERMISSIONS as $permission) {
            if ($user->can($permission->value)) {
                return true;
            }
        }

        return false;
    }

    public function view(User $user, Import $import): bool
    {
        if (! $user->status->canAuthenticate()) {
            return false;
        }

        if ((int) $import->user_id === (int) $user->getKey()) {
            return true;
        }

        return $this->reviews($user, (string) $import->importer);
    }

    /**
     * Undoing a run (D-29): a real import (not a dry run) that completed and
     * has neither been undone nor asked to be, read by its owner or by a
     * reviewer, who must hold the entity's `delete` key. Each record is then
     * re-checked against the entity's own `delete` policy by
     * ImportUndoService, so a team manager undoes a run only as far as the
     * team's records go, and the rest are reported as kept.
     */
    public function undo(User $user, Import $import): bool
    {
        if (! $this->view($user, $import)) {
            return false;
        }

        if (
            (bool) $import->getAttribute('dry_run')
            || $import->getAttribute('completed_at') === null
            || $import->getAttribute('undo_requested_at') !== null
            || $import->getAttribute('undone_at') !== null
        ) {
            return false;
        }

        $model = self::entityOf((string) $import->importer);

        return $model !== null && $user->can($model::permissionGroup().'.delete');
    }

    /**
     * Whether the user may review other users' runs of this importer:
     * `imports.view` plus all-level reach on the importer's entity.
     */
    public function reviews(User $user, string $importer): bool
    {
        if (! $user->can(Permission::ImportsView->value)) {
            return false;
        }

        $model = self::entityOf($importer);

        return $model !== null && $user->can($model::permissionGroup().'.view_all');
    }

    /**
     * The owned entity an importer class writes, or null for a class that is
     * not one of the application's importers (a run whose importer was
     * removed, or a forged class name) — such a run is neither reviewed nor
     * undone.
     *
     * @return class-string<OwnedRecord>|null
     */
    public static function entityOf(string $importer): ?string
    {
        if (! is_subclass_of($importer, Importer::class)) {
            return null;
        }

        $model = $importer::getModel();

        return is_subclass_of($model, OwnedRecord::class) ? $model : null;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ?Import $import = null): bool
    {
        return false;
    }

    public function delete(User $user, ?Import $import = null): bool
    {
        return false;
    }

    public function restore(User $user, ?Import $import = null): bool
    {
        return false;
    }

    public function forceDelete(User $user, ?Import $import = null): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
