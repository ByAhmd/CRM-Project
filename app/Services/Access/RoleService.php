<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Exceptions\Access\LastSuperAdminException;
use App\Exceptions\Access\LockedRoleException;
use App\Exceptions\Access\SelfStatusChangeException;
use App\Exceptions\Access\SuperAdminMembershipException;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Runtime role administration (decision D-3) with its audit trail and guards.
 *
 * - super_admin is locked: it cannot be renamed, deleted or lose permissions.
 * - the seeded roles keep their machine name (code refers to it) but their
 *   permissions and display names are editable.
 * - the last active super admin can never be demoted or switched off.
 * - super_admin is granted, removed or edited only by a `roles.manage` holder.
 * - nobody switches off their own account.
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
     * Replace a user's roles.
     *
     * - the last active super admin is never demoted;
     * - super_admin is granted or removed only by an actor holding
     *   `roles.manage`, so `users.manage` never turns into role administration.
     *
     * Every panel path passes the signed-in actor. A null actor is the trusted
     * system context only (the `app:onboard` console command creating the
     * first super admin, where no panel user exists yet).
     *
     * @param  list<string>  $roleNames
     */
    public function syncUserRoles(User $user, array $roleNames, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $roleNames, $actor): void {
            $before = $user->getRoleNames()->sort()->values()->all();

            $hadSuperAdmin = in_array(CrmRole::SuperAdmin->value, $before, true);
            $keepsSuperAdmin = in_array(CrmRole::SuperAdmin->value, $roleNames, true);

            if ($hadSuperAdmin && ! $keepsSuperAdmin && $this->isLastActiveSuperAdmin($user)) {
                throw LastSuperAdminException::make();
            }

            if ($hadSuperAdmin !== $keepsSuperAdmin && $actor !== null && ! $this->mayAdministerSuperAdmins($actor)) {
                throw SuperAdminMembershipException::make();
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

    /**
     * Guards a status change made by an administrator, before it is written.
     * The rules are checked most specific first, so the refusal names the
     * real reason:
     *
     * - nobody switches off their own account (disabled or pending);
     * - the last active super admin is never switched off — pending cannot
     *   sign in either, so both non-active statuses are refused;
     * - a super admin's status is changed only by a `roles.manage` holder;
     * - pending is reached only through an invitation, never set back by hand
     *   (a pending account re-activates itself through the password link).
     *
     * A status equal to the current one is not a change and always passes.
     *
     * @throws SelfStatusChangeException
     * @throws LastSuperAdminException
     * @throws SuperAdminMembershipException
     * @throws InvalidArgumentException when a non-pending account would be set to pending
     */
    public function assertStatusChange(User $target, UserStatus $new, User $actor): void
    {
        if ($new === $target->status) {
            return;
        }

        if (! $new->canAuthenticate() && $target->is($actor)) {
            throw SelfStatusChangeException::make();
        }

        if (! $new->canAuthenticate() && $target->status->canAuthenticate() && $this->isLastActiveSuperAdmin($target)) {
            throw LastSuperAdminException::make();
        }

        if ($target->isSuperAdmin() && ! $this->mayAdministerSuperAdmins($actor)) {
            throw SuperAdminMembershipException::make();
        }

        if ($new === UserStatus::Pending) {
            throw new InvalidArgumentException(__('users.validation.pending_by_invitation'));
        }
    }

    /** Super admin membership and super admin accounts belong to role administration (A-12). */
    public function mayAdministerSuperAdmins(User $actor): bool
    {
        return $actor->can(Permission::RolesManage->value);
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
