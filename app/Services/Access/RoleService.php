<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Exceptions\Access\LastSuperAdminException;
use App\Exceptions\Access\LockedRoleException;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Runtime role administration (decision D-3) with its audit trail and guards.
 *
 * - super_admin is locked: it cannot be renamed, deleted or lose permissions.
 * - the seeded roles keep their machine name (code refers to it) but their
 *   permissions and display names are editable.
 * - the last active super admin can never be demoted.
 * - every permission change is written to the ledger with the before/after sets.
 */
final class RoleService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * @param  list<string>  $permissions  permission keys (Permission enum values)
     */
    public function syncPermissions(Role $role, array $permissions, ?User $actor = null): void
    {
        if ($role->isLocked()) {
            throw LockedRoleException::make();
        }

        $known = array_values(array_intersect($permissions, Permission::values()));

        DB::transaction(function () use ($role, $known, $actor): void {
            $before = $role->permissions()->pluck('name')->sort()->values()->all();

            $role->syncPermissions($known);
            $this->registrar->forgetCachedPermissions();

            $after = collect($known)->sort()->values()->all();

            if ($before !== $after) {
                $this->audit->record(ActivityLogEvent::RolePermissionsChanged, $role, $actor, [
                    'subject_label' => $role->display_name,
                    'added' => array_values(array_diff($after, $before)),
                    'removed' => array_values(array_diff($before, $after)),
                ]);
            }
        });
    }

    public function delete(Role $role, ?User $actor = null): void
    {
        if ($role->isLocked() || $role->isSeeded()) {
            throw LockedRoleException::make();
        }

        DB::transaction(function () use ($role): void {
            $role->delete();
            $this->registrar->forgetCachedPermissions();
        });
    }

    /**
     * Replace a user's roles, refusing to demote the last active super admin.
     *
     * @param  list<string>  $roleNames
     */
    public function syncUserRoles(User $user, array $roleNames, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $roleNames, $actor): void {
            $before = $user->getRoleNames()->sort()->values()->all();

            if (in_array(CrmRole::SuperAdmin->value, $before, true)
                && ! in_array(CrmRole::SuperAdmin->value, $roleNames, true)
                && $this->isLastActiveSuperAdmin($user)) {
                throw LastSuperAdminException::make();
            }

            $user->syncRoles($roleNames);
            $this->registrar->forgetCachedPermissions();

            $after = collect($roleNames)->sort()->values()->all();

            if ($before !== $after) {
                $this->audit->record(ActivityLogEvent::UserRolesChanged, $user, $actor, [
                    'subject_label' => $user->name,
                    'added' => array_values(array_diff($after, $before)),
                    'removed' => array_values(array_diff($before, $after)),
                ]);
            }
        });
    }

    /** True when disabling or deleting this user would leave no active super admin. */
    public function isLastActiveSuperAdmin(User $user): bool
    {
        if (! $user->isSuperAdmin()) {
            return false;
        }

        return User::query()
            ->whereKeyNot($user->getKey())
            ->where('status', 'active')
            ->role(CrmRole::SuperAdmin->value)
            ->doesntExist();
    }
}
