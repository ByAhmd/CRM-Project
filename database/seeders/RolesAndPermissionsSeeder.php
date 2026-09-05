<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CrmRole;
use App\Enums\Permission as PermissionKey;
use App\Models\Role;
use App\Support\Access\RolePermissionMatrix;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the permission catalogue and the six default roles (decisions D-3, A-12).
 *
 * Idempotent and safe on every deploy:
 * - every Permission enum case gets a row; rows for keys the code no longer
 *   declares are removed so the Roles screen cannot offer dead permissions;
 * - the six seeded roles are created if missing and their bilingual names
 *   refreshed; super_admin always receives every permission;
 * - the other five roles are reset to the matrix ONLY when created. Once a
 *   super admin has edited them in the panel (D-3), their permissions are
 *   theirs — the seeder must not silently undo a runtime decision.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $permissions = $this->syncPermissionCatalogue();

        foreach (RolePermissionMatrix::defaults() as $roleName => $granted) {
            $role = CrmRole::from($roleName);

            /** @var Role $model */
            $model = Role::query()->firstOrNew(['name' => $roleName, 'guard_name' => 'web']);
            $isNew = ! $model->exists;

            $model->name_ar = __('enums.roles.'.$roleName, [], 'ar');
            $model->name_en = __('enums.roles.'.$roleName, [], 'en');
            $model->save();

            if ($isNew || $role->isLocked()) {
                $model->syncPermissions(array_values(array_intersect_key(
                    $permissions,
                    array_flip(array_map(static fn (PermissionKey $permission): string => $permission->value, $granted)),
                )));
            }
        }

        $registrar->forgetCachedPermissions();
    }

    /**
     * @return array<string, \Spatie\Permission\Contracts\Permission>
     */
    private function syncPermissionCatalogue(): array
    {
        $known = PermissionKey::values();
        $rows = [];

        foreach ($known as $name) {
            $rows[$name] = Permission::findOrCreate($name, 'web');
        }

        Permission::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', $known)
            ->delete();

        return $rows;
    }
}
