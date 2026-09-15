<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Authorisation;

use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Exceptions\Access\SuperAdminMembershipException;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Services\Access\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Authorisation audit probes: user administration guards (A-12, PERMISSIONS.md
 * "Guards above the permissions"). Each probe fails while the defect exists.
 */
final class UserAdministrationProbeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
    }

    #[Test]
    public function an_admin_cannot_promote_themselves_to_super_admin_and_gain_roles_manage(): void
    {
        $this->superAdmin();
        $admin = $this->admin();

        $this->assertFalse($admin->can(Permission::RolesManage->value));

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->fillForm(['roles' => [CrmRole::Admin->value, CrmRole::SuperAdmin->value]])
            ->call('save');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = $admin->fresh();

        $this->assertNotNull($admin);
        $this->assertFalse($admin->hasRole(CrmRole::SuperAdmin->value), 'roles.manage is reserved to super admins (A-12): an admin must not be able to grant super_admin to themselves');
        $this->assertFalse($admin->can(Permission::RolesManage->value));
    }

    #[Test]
    public function an_admin_cannot_invite_a_new_super_admin(): void
    {
        $this->superAdmin();
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Shadow Owner',
                'email' => 'shadow@example.com',
                'locale' => 'en',
                'roles' => [CrmRole::SuperAdmin->value],
            ])
            ->call('create');

        $this->assertDatabaseMissing('users', ['email' => 'shadow@example.com']);
    }

    #[Test]
    public function an_admin_cannot_disable_a_super_admin(): void
    {
        $this->superAdmin();
        $second = $this->makeUser(CrmRole::SuperAdmin, ['name' => 'Second Super']);
        $admin = $this->admin();

        // The edit page of a super admin is closed to a users.manage holder
        // without roles.manage (UserPolicy::update), so the form cannot even
        // be filled; the service refuses the same change on any other path.
        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $second->getRouteKey()])
            ->assertForbidden();

        try {
            app(RoleService::class)->assertStatusChange($second, UserStatus::Disabled, $admin);
            $this->fail('RoleService let an admin without roles.manage switch a super admin off');
        } catch (SuperAdminMembershipException) {
            // expected
        }

        $this->assertSame(UserStatus::Active, $second->fresh()?->status, 'a role without roles.manage must not be able to switch a super admin off');
    }

    #[Test]
    public function nobody_can_disable_their_own_account_from_the_edit_form(): void
    {
        $this->superAdmin();
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->fillForm(['status' => UserStatus::Disabled->value])
            ->call('save');

        $this->assertSame(UserStatus::Active, $admin->fresh()?->status, 'PERMISSIONS.md: nobody may delete or disable their own account');
    }

    #[Test]
    public function the_last_active_super_admin_cannot_be_set_back_to_pending(): void
    {
        $superAdmin = $this->superAdmin();

        Livewire::actingAs($superAdmin)
            ->test(EditUser::class, ['record' => $superAdmin->getRouteKey()])
            ->fillForm(['status' => UserStatus::Pending->value])
            ->call('save');

        $this->assertSame(UserStatus::Active, $superAdmin->fresh()?->status, 'pending cannot authenticate either: the guard must cover every non-active status, not only disabled');
    }
}
