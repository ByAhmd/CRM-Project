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
 * rather than App\Models\Import. Rows are written by Filament's ImportAction
 * only and never edited or removed by hand — retention pruning is the only
 * way one disappears.
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
     * Whether the user may review other users' runs of this importer:
     * `imports.view` plus all-level reach on the importer's entity.
     */
    public function reviews(User $user, string $importer): bool
    {
        if (! $user->can(Permission::ImportsView->value)) {
            return false;
        }

        if (! is_subclass_of($importer, Importer::class)) {
            return false;
        }

        $model = $importer::getModel();

        if (! is_subclass_of($model, OwnedRecord::class)) {
            return false;
        }

        return $user->can($model::permissionGroup().'.view_all');
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
