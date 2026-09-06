<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Import;
use App\Models\User;

/**
 * Import history (module 18). A user always sees their own runs; `imports.view`
 * opens every run. Rows are written by Filament's ImportAction only and never
 * edited or removed by hand — retention pruning is the only way one disappears.
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

    /** Anyone who may import something, or who may review every run. */
    public function viewAny(User $user): bool
    {
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
        return $import->isOwnedBy($user) || $user->can(Permission::ImportsView->value);
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
