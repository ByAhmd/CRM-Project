<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Models\Role;
use App\Support\Access\RolePermissionMatrix;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission as PermissionModel;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The seeder is the reviewed baseline of decision D-3: every permission the
 * code declares exists as a row, the six roles carry the matrix, and a
 * super admin's later edits survive a redeploy.
 */
final class RoleSeedingTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function every_permission_case_has_a_row_and_nothing_else_does(): void
    {
        PermissionModel::findOrCreate('stale.permission', 'web');

        $this->seedAccess();

        $rows = PermissionModel::query()->where('guard_name', 'web')->pluck('name')->sort()->values()->all();
        $expected = Permission::values();
        sort($expected);

        $this->assertSame($expected, $rows);
    }

    #[Test]
    public function the_six_roles_are_created_with_bilingual_names_and_matrix_permissions(): void
    {
        $this->seedAccess();

        foreach (RolePermissionMatrix::defaults() as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->firstOrFail();

            $this->assertNotSame('', $role->name_ar, "{$roleName} lacks an Arabic name");
            $this->assertNotSame('', $role->name_en, "{$roleName} lacks an English name");
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $role->name_ar);

            $expected = array_map(static fn (Permission $permission): string => $permission->value, $permissions);
            sort($expected);

            $actual = $role->permissions()->pluck('name')->sort()->values()->all();

            $this->assertSame($expected, $actual, "{$roleName} permissions drifted from RolePermissionMatrix");
        }
    }

    #[Test]
    public function the_super_admin_role_holds_every_permission(): void
    {
        $this->seedAccess();

        $role = Role::query()->where('name', CrmRole::SuperAdmin->value)->firstOrFail();

        $this->assertSame(count(Permission::cases()), $role->permissions()->count());
    }

    #[Test]
    public function reseeding_is_idempotent_and_keeps_runtime_edits_on_editable_roles(): void
    {
        $this->seedAccess();

        $salesRep = Role::query()->where('name', CrmRole::SalesRep->value)->firstOrFail();
        $salesRep->givePermissionTo(Permission::LeadAssign->value);

        $superAdmin = Role::query()->where('name', CrmRole::SuperAdmin->value)->firstOrFail();
        $superAdmin->revokePermissionTo(Permission::AuditView->value);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(count(CrmRole::cases()), Role::query()->count());
        $this->assertTrue($salesRep->refresh()->hasPermissionTo(Permission::LeadAssign->value), 'runtime grant was silently reverted (D-3)');
        $this->assertTrue($superAdmin->refresh()->hasPermissionTo(Permission::AuditView->value), 'super_admin must be re-granted everything');
    }
}
