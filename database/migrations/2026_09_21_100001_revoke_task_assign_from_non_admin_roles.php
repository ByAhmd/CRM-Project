<?php

declare(strict_types=1);

use App\Enums\CrmRole;
use App\Enums\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data migration for decision D-14 (2026-09-21): task assignment is
 * admin-only, so `task.assign` belongs to super_admin and admin alone.
 *
 * RolePermissionMatrix no longer grants the key to sales_manager, but the
 * seeder deliberately never re-syncs roles that already exist (D-3: runtime
 * edits survive a redeploy), so an existing install keeps the old grant until
 * this migration revokes it — from every role except super_admin and admin,
 * custom roles included. The rows are touched defensively (a fresh install
 * runs this before anything is seeded, so both tables may be empty) and the
 * spatie permission cache is flushed either way. down() re-grants the key to
 * sales_manager only, the single seeded role that held it before D-14.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissionId = $this->permissionId();

        if ($permissionId !== null) {
            $keptRoleIds = DB::table($this->table('roles'))
                ->whereIn('name', [CrmRole::SuperAdmin->value, CrmRole::Admin->value])
                ->pluck('id');

            DB::table($this->table('role_has_permissions'))
                ->where('permission_id', $permissionId)
                ->whereNotIn('role_id', $keptRoleIds)
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = $this->permissionId();
        $roleId = DB::table($this->table('roles'))
            ->where('name', CrmRole::SalesManager->value)
            ->value('id');

        if ($permissionId !== null && $roleId !== null) {
            $exists = DB::table($this->table('role_has_permissions'))
                ->where('permission_id', $permissionId)
                ->where('role_id', $roleId)
                ->exists();

            if (! $exists) {
                DB::table($this->table('role_has_permissions'))->insert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function permissionId(): int|string|null
    {
        /** @var int|string|null $id */
        $id = DB::table($this->table('permissions'))
            ->where('name', Permission::TaskAssign->value)
            ->where('guard_name', 'web')
            ->value('id');

        return $id;
    }

    private function table(string $key): string
    {
        return (string) config('permission.table_names.'.$key, $key);
    }
};
