<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Exceptions\Access\LastSuperAdminException;
use App\Exceptions\Access\LockedRoleException;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Services\Access\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class RoleServiceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private RoleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->service = app(RoleService::class);
    }

    #[Test]
    public function syncing_permissions_writes_the_difference_to_the_ledger(): void
    {
        $actor = $this->superAdmin();
        $role = Role::query()->where('name', CrmRole::SalesRep->value)->firstOrFail();

        $this->service->syncPermissions($role, [Permission::LeadViewAny->value, Permission::LeadAssign->value], $actor);

        $this->assertTrue($role->refresh()->hasPermissionTo(Permission::LeadAssign->value));
        $this->assertFalse($role->refresh()->hasPermissionTo(Permission::LeadCreate->value));

        $log = ActivityLog::query()->where('description', ActivityLogEvent::RolePermissionsChanged->value)->latest('id')->firstOrFail();

        $this->assertSame($actor->getKey(), (int) $log->causer_id);
        $this->assertContains(Permission::LeadAssign->value, $log->properties->get('added'));
        $this->assertContains(Permission::LeadCreate->value, $log->properties->get('removed'));
    }

    #[Test]
    public function unknown_permission_keys_are_ignored(): void
    {
        $role = Role::query()->where('name', CrmRole::ReadOnly->value)->firstOrFail();

        $this->service->syncPermissions($role, ['not.a_permission', Permission::LeadViewAny->value]);

        $this->assertSame([Permission::LeadViewAny->value], $role->refresh()->permissions()->pluck('name')->all());
    }

    #[Test]
    public function the_super_admin_role_cannot_lose_permissions_or_be_deleted(): void
    {
        $role = Role::query()->where('name', CrmRole::SuperAdmin->value)->firstOrFail();

        $this->expectException(LockedRoleException::class);
        $this->service->syncPermissions($role, []);
    }

    #[Test]
    public function seeded_roles_cannot_be_deleted_but_custom_roles_can(): void
    {
        $seeded = Role::query()->where('name', CrmRole::Support->value)->firstOrFail();
        $custom = Role::query()->create(['name' => 'regional_manager', 'guard_name' => 'web', 'name_ar' => 'مدير إقليمي', 'name_en' => 'Regional manager']);

        $this->service->delete($custom);
        $this->assertDatabaseMissing('roles', ['name' => 'regional_manager']);

        $this->expectException(LockedRoleException::class);
        $this->service->delete($seeded);
    }

    #[Test]
    public function the_last_active_super_admin_cannot_be_demoted(): void
    {
        $only = $this->superAdmin();

        $this->assertTrue($this->service->isLastActiveSuperAdmin($only));

        $this->expectException(LastSuperAdminException::class);
        $this->service->syncUserRoles($only, [CrmRole::Admin->value]);
    }

    #[Test]
    public function a_super_admin_can_be_demoted_when_another_active_one_exists(): void
    {
        $first = $this->superAdmin();
        $second = $this->superAdmin();
        $actor = $second;

        $this->service->syncUserRoles($first, [CrmRole::Admin->value], $actor);

        $this->assertSame([CrmRole::Admin->value], $first->refresh()->getRoleNames()->all());

        $log = ActivityLog::query()->where('description', ActivityLogEvent::UserRolesChanged->value)->latest('id')->firstOrFail();
        $this->assertSame([CrmRole::Admin->value], $log->properties->get('added'));
        $this->assertSame([CrmRole::SuperAdmin->value], $log->properties->get('removed'));
    }

    #[Test]
    public function a_disabled_super_admin_does_not_count_as_active(): void
    {
        $active = $this->superAdmin();
        $disabled = $this->makeUser(CrmRole::SuperAdmin, ['status' => 'disabled']);

        $this->assertTrue($this->service->isLastActiveSuperAdmin($active));
        $this->assertFalse($this->service->isLastActiveSuperAdmin($disabled));
    }
}
