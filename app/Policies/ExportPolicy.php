<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Export;
use App\Models\User;

/**
 * Export history (module 18, decision D-13). A user always sees their own runs
 * and downloads their own files; `exports.view` opens every run. Rows are
 * written by Filament's ExportAction only — retention pruning is the only way
 * one disappears.
 */
final class ExportPolicy
{
    /**
     * @var list<Permission>
     */
    private const EXPORT_PERMISSIONS = [
        Permission::LeadExport,
        Permission::ContactExport,
        Permission::AccountExport,
        Permission::DealExport,
        Permission::TaskExport,
        Permission::ActivityExport,
    ];

    /** Anyone who may export something, or who may review every run. */
    public function viewAny(User $user): bool
    {
        if ($user->can(Permission::ExportsView->value)) {
            return true;
        }

        foreach (self::EXPORT_PERMISSIONS as $permission) {
            if ($user->can($permission->value)) {
                return true;
            }
        }

        return false;
    }

    public function view(User $user, Export $export): bool
    {
        return $export->isOwnedBy($user) || $user->can(Permission::ExportsView->value);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ?Export $export = null): bool
    {
        return false;
    }

    public function delete(User $user, ?Export $export = null): bool
    {
        return false;
    }

    public function restore(User $user, ?Export $export = null): bool
    {
        return false;
    }

    public function forceDelete(User $user, ?Export $export = null): bool
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
