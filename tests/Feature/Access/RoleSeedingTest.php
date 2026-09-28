<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Models\Role;
use App\Support\Access\RolePermissionMatrix;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission as PermissionModel;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The seeder is the reviewed baseline of decision D-3: every permission the
 * code declares exists as a row, the seven roles carry the matrix, and a
 * super admin's later edits survive a redeploy — while a role added to the
 * matrix later (the employee, D-15) arrives with its defaults on an existing
 * install.
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
    public function the_seven_roles_are_created_with_bilingual_names_and_matrix_permissions(): void
    {
        $this->seedAccess();

        // D-15 (2026-09-28): the matrix names every seeded role, the employee included.
        $this->assertSame(CrmRole::values(), array_keys(RolePermissionMatrix::defaults()));
        $this->assertSame(7, Role::query()->count());

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

    #[Test]
    public function a_redeploy_creates_the_employee_role_with_its_defaults_on_an_existing_install(): void
    {
        // D-15 (2026-09-28): an install seeded before the role existed, with a
        // runtime edit on another role, gains the employee at the next db:seed.
        $this->seedAccess();

        $salesRep = Role::query()->where('name', CrmRole::SalesRep->value)->firstOrFail();
        $salesRep->givePermissionTo(Permission::LeadAssign->value);
        Role::query()->where('name', CrmRole::Employee->value)->firstOrFail()->delete();

        $this->seed(RolesAndPermissionsSeeder::class);

        $employee = Role::query()->where('name', CrmRole::Employee->value)->firstOrFail();
        $expected = array_map(static fn (Permission $permission): string => $permission->value, RolePermissionMatrix::for(CrmRole::Employee));
        sort($expected);

        $this->assertSame('موظف', $employee->name_ar);
        $this->assertSame('Employee', $employee->name_en);
        $this->assertSame($expected, $employee->permissions()->pluck('name')->sort()->values()->all());
        $this->assertTrue($salesRep->refresh()->hasPermissionTo(Permission::LeadAssign->value), 'the other roles keep their runtime edits (D-3)');
    }

    #[Test]
    public function a_super_admins_edit_of_the_employee_role_survives_a_redeploy(): void
    {
        $this->seedAccess();

        $employee = Role::query()->where('name', CrmRole::Employee->value)->firstOrFail();
        $employee->revokePermissionTo(Permission::TaskDelete->value);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertFalse($employee->refresh()->hasPermissionTo(Permission::TaskDelete->value), 'D-15 keeps D-3: the employee role is editable at runtime');
    }

    #[Test]
    public function a_hand_made_employee_role_is_set_aside_before_the_seeded_one_is_created(): void
    {
        // An install whose super admin built an `employee` role of their own
        // before D-15 reserved the key.
        $this->seedAccess();
        Role::query()->where('name', CrmRole::Employee->value)->firstOrFail()->delete();

        $handMade = Role::query()->create(['name' => CrmRole::Employee->value, 'guard_name' => 'web', 'name_ar' => 'موظف المبيعات', 'name_en' => 'Sales staff']);
        $handMade->syncPermissions([Permission::LeadViewAny->value, Permission::TaskViewAny->value]);
        $holder = $this->makeUser(CrmRole::SalesRep);
        $holder->syncRoles([$handMade->name]);

        // The deploy runs `migrate` before `db:seed`.
        $this->runEmployeeKeyMigration('up');
        $this->seed(RolesAndPermissionsSeeder::class);

        $legacy = Role::query()->where('name', 'employee_legacy')->firstOrFail();
        $this->assertSame($handMade->getKey(), $legacy->getKey());
        $this->assertSame('Sales staff', $legacy->name_en, 'the hand-made role keeps its own names');
        $this->assertTrue($legacy->hasPermissionTo(Permission::LeadViewAny->value), 'and its own permissions');
        $this->assertTrue($holder->refresh()->hasRole('employee_legacy'), 'and its users');

        $employee = Role::query()->where('name', CrmRole::Employee->value)->firstOrFail();
        $expected = array_map(static fn (Permission $permission): string => $permission->value, RolePermissionMatrix::for(CrmRole::Employee));
        sort($expected);

        $this->assertNotSame($handMade->getKey(), $employee->getKey());
        $this->assertSame($expected, $employee->permissions()->pluck('name')->sort()->values()->all(), 'the seeded employee arrives with the D-15 defaults');

        // Reversible while the seeded role is absent.
        $employee->delete();
        $this->runEmployeeKeyMigration('down');
        $this->assertSame(CrmRole::Employee->value, $legacy->refresh()->name);
    }

    #[Test]
    public function the_seeded_employee_role_is_left_alone_by_the_key_migration(): void
    {
        $this->seedAccess();
        $employee = Role::query()->where('name', CrmRole::Employee->value)->firstOrFail();

        $this->runEmployeeKeyMigration('up');

        $this->assertSame(CrmRole::Employee->value, $employee->refresh()->name);
        $this->assertFalse(Role::query()->where('name', 'like', 'employee_legacy%')->exists());
    }

    /** Runs one direction of the D-15 data migration that frees the `employee` key. */
    private function runEmployeeKeyMigration(string $direction): void
    {
        $migration = require database_path('migrations/2026_09_28_100003_set_aside_a_hand_made_employee_role.php');
        $this->assertInstanceOf(Migration::class, $migration);

        $migration->{$direction}();
    }
}
