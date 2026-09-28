<?php

declare(strict_types=1);

use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Models\Role;
use App\Support\Access\RolePermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data migration for decision D-15 (2026-09-28): frees the `employee` key for
 * the seeded Employee role.
 *
 * Before D-15, `employee` was an ordinary key a super admin could give a role
 * of their own in the Roles screen. RolesAndPermissionsSeeder applies a
 * role's matrix defaults only when it creates the row (D-3), so on an install
 * that already holds a hand-made `employee` the next deploy's `db:seed` would
 * adopt it as the seeded Employee — relabelled «موظف» / Employee and locked
 * against renaming — while it kept whatever it granted, sales access
 * included. The deploy runs `migrate` before `db:seed`, so this migration
 * moves such a role aside first: it keeps its users, permissions and display
 * names under the key `employee_legacy` (or `employee_legacy_2`, … when that
 * key is taken), the rename is recorded in the audit ledger as a role update,
 * and the seeder then creates the D-15 role with its defaults.
 *
 * A role already holding exactly the D-15 defaults is the seeded one (or
 * indistinguishable from it) and is left alone, as is every install without
 * an `employee` row — a fresh install runs this before anything is seeded.
 * down() moves `employee_legacy` back only while no `employee` role exists.
 */
return new class extends Migration
{
    private const string LEGACY_KEY = 'employee_legacy';

    public function up(): void
    {
        /** @var Role|null $role */
        $role = Role::query()
            ->where('name', CrmRole::Employee->value)
            ->where('guard_name', 'web')
            ->first();

        if ($role !== null && ! $this->holdsExactlyTheDefaults($role)) {
            $role->name = $this->freeLegacyKey();
            $role->save();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $employeeExists = Role::query()
            ->where('name', CrmRole::Employee->value)
            ->where('guard_name', 'web')
            ->exists();

        /** @var Role|null $legacy */
        $legacy = Role::query()
            ->where('name', self::LEGACY_KEY)
            ->where('guard_name', 'web')
            ->first();

        if (! $employeeExists && $legacy !== null) {
            $legacy->name = CrmRole::Employee->value;
            $legacy->save();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function holdsExactlyTheDefaults(Role $role): bool
    {
        /** @var list<string> $held */
        $held = DB::table($this->table('role_has_permissions'))
            ->join($this->table('permissions'), $this->table('permissions').'.id', '=', $this->table('role_has_permissions').'.permission_id')
            ->where($this->table('role_has_permissions').'.role_id', $role->getKey())
            ->pluck($this->table('permissions').'.name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();

        $defaults = array_map(
            static fn (Permission $permission): string => $permission->value,
            RolePermissionMatrix::for(CrmRole::Employee),
        );

        sort($held);
        sort($defaults);

        return $held === $defaults;
    }

    private function freeLegacyKey(): string
    {
        $key = self::LEGACY_KEY;
        $suffix = 1;

        while (Role::query()->where('name', $key)->where('guard_name', 'web')->exists()) {
            $suffix++;
            $key = self::LEGACY_KEY.'_'.$suffix;
        }

        return $key;
    }

    private function table(string $key): string
    {
        return (string) config('permission.table_names.'.$key, $key);
    }
};
