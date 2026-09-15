<?php

declare(strict_types=1);

namespace App\Policies;

use App\Contracts\OwnedRecord;
use App\Enums\Permission;
use App\Models\User;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * Export history (module 18, decisions D-4, D-13).
 *
 * An export file is record data, so reaching it must never widen the reader's
 * scope: a user always sees their own runs and downloads their own files;
 * `exports.view` opens another user's run only when the holder reaches every
 * record of the run's entity (`{entity}.view_all`) — a team-scoped manager
 * reviews their own runs only, an organisation-wide reader reviews all.
 *
 * An account that may no longer sign in (disabled, pending) reaches nothing,
 * even with a live session: Filament's download route runs outside the panel
 * middleware, so the status is checked here.
 *
 * The policy is registered for Filament's base Export model as well
 * (AppServiceProvider), because Filament's download route binds that class
 * rather than App\Models\Export. Rows are written by Filament's ExportAction
 * only — retention pruning is the only way one disappears.
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

    /** Anyone who may export something, or who may review runs. */
    public function viewAny(User $user): bool
    {
        if (! $user->status->canAuthenticate()) {
            return false;
        }

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
        if (! $user->status->canAuthenticate()) {
            return false;
        }

        if ((int) $export->user_id === (int) $user->getKey()) {
            return true;
        }

        return $this->reviews($user, (string) $export->exporter);
    }

    /**
     * Whether the user may review other users' runs of this exporter:
     * `exports.view` plus all-level reach on the exporter's entity.
     */
    public function reviews(User $user, string $exporter): bool
    {
        if (! $user->can(Permission::ExportsView->value)) {
            return false;
        }

        if (! is_subclass_of($exporter, Exporter::class)) {
            return false;
        }

        $model = $exporter::getModel();

        if (! is_subclass_of($model, OwnedRecord::class)) {
            return false;
        }

        return $user->can($model::permissionGroup().'.view_all');
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
