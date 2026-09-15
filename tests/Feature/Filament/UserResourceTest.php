<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Exceptions\Access\LastSuperAdminException;
use App\Exceptions\Access\SuperAdminMembershipException;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Access\RoleService;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class UserResourceTest extends TestCase
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
    public function an_admin_lists_users_and_a_sales_rep_is_refused(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();

        $this->actingAs($admin)->get(UserResource::getUrl('index'))->assertOk();
        $this->actingAs($rep)->get(UserResource::getUrl('index'))->assertForbidden();
        $this->actingAs($rep)->get(UserResource::getUrl('create'))->assertForbidden();
        $this->actingAs($rep)->get(UserResource::getUrl('edit', ['record' => $admin]))->assertForbidden();

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->assertCanSeeTableRecords([$admin, $rep]);
    }

    #[Test]
    public function an_admin_changes_roles_team_and_status(): void
    {
        $admin = $this->admin();
        $team = $this->makeTeam();
        $target = $this->salesRep();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $target->getRouteKey()])
            ->assertSchemaStateSet(['roles' => [CrmRole::SalesRep->value]])
            ->fillForm([
                'roles' => [CrmRole::SalesManager->value],
                'team_id' => $team->getKey(),
                'status' => UserStatus::Disabled->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $target->refresh();

        $this->assertSame([CrmRole::SalesManager->value], $target->getRoleNames()->all());
        $this->assertSame($team->getKey(), $target->team_id);
        $this->assertSame(UserStatus::Disabled, $target->status);
    }

    #[Test]
    public function the_last_active_super_admin_cannot_be_disabled_or_demoted_from_the_form(): void
    {
        $superAdmin = $this->superAdmin();
        // A role administrator who is not a super admin: the last-super-admin
        // guard answers, not the self guard.
        $roleAdministrator = $this->userWithPermissions(null, [Permission::UsersManage, Permission::RolesManage]);

        Livewire::actingAs($roleAdministrator)
            ->test(EditUser::class, ['record' => $superAdmin->getRouteKey()])
            ->fillForm(['status' => UserStatus::Disabled->value])
            ->call('save')
            ->assertNotified(__('users.validation.last_super_admin'));

        $this->assertSame(UserStatus::Active, $superAdmin->refresh()->status);

        Livewire::actingAs($superAdmin)
            ->test(EditUser::class, ['record' => $superAdmin->getRouteKey()])
            ->fillForm(['roles' => [CrmRole::Admin->value]])
            ->call('save')
            ->assertNotified(__('users.validation.last_super_admin'));

        $this->assertTrue($superAdmin->refresh()->isSuperAdmin());
    }

    #[Test]
    public function the_last_active_super_admin_cannot_be_set_back_to_pending(): void
    {
        $superAdmin = $this->superAdmin();
        $roleAdministrator = $this->userWithPermissions(null, [Permission::UsersManage, Permission::RolesManage]);

        Livewire::actingAs($roleAdministrator)
            ->test(EditUser::class, ['record' => $superAdmin->getRouteKey()])
            ->fillForm(['status' => UserStatus::Pending->value])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame(UserStatus::Active, $superAdmin->refresh()->status);

        // Behind the form's options, the service names the specific reason.
        $this->expectException(LastSuperAdminException::class);
        app(RoleService::class)->assertStatusChange($superAdmin, UserStatus::Pending, $roleAdministrator);
    }

    #[Test]
    public function nobody_switches_off_their_own_account(): void
    {
        $this->superAdmin();
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->fillForm(['status' => UserStatus::Disabled->value])
            ->call('save')
            ->assertNotified(__('users.validation.self_status'));

        $this->assertSame(UserStatus::Active, $admin->refresh()->status);
    }

    #[Test]
    public function pending_is_offered_only_while_the_account_is_still_pending(): void
    {
        $admin = $this->admin();
        $active = $this->salesRep();
        $pending = $this->makeUser(CrmRole::SalesRep, ['status' => UserStatus::Pending]);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $active->getRouteKey()])
            ->assertFormFieldExists('status', fn (Select $field): bool => array_keys($field->getOptions()) === [UserStatus::Active->value, UserStatus::Disabled->value])
            ->fillForm(['status' => UserStatus::Pending->value])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame(UserStatus::Active, $active->refresh()->status);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $pending->getRouteKey()])
            ->assertFormFieldExists('status', fn (Select $field): bool => array_key_exists(UserStatus::Pending->value, $field->getOptions()))
            ->fillForm(['name' => 'Still Pending'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(UserStatus::Pending, $pending->refresh()->status);

        // Behind the form's options, the service refuses pending by hand too.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('users.validation.pending_by_invitation'));
        app(RoleService::class)->assertStatusChange($active, UserStatus::Pending, $admin);
    }

    #[Test]
    public function an_admin_is_not_offered_super_admin_and_cannot_touch_a_super_admin_while_a_super_admin_can(): void
    {
        $superAdmin = $this->superAdmin();
        $second = $this->superAdmin();
        $admin = $this->admin();
        $rep = $this->salesRep();

        // admin: users.manage without roles.manage (A-12)
        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $rep->getRouteKey()])
            ->assertFormFieldExists('roles', fn (Select $field): bool => ! array_key_exists(CrmRole::SuperAdmin->value, $field->getOptions()))
            ->fillForm(['roles' => [CrmRole::SalesRep->value, CrmRole::SuperAdmin->value]])
            ->call('save')
            ->assertHasFormErrors(['roles.1']);

        $this->assertFalse($rep->refresh()->isSuperAdmin());

        $this->assertFalse($admin->can('update', $second));
        $this->assertFalse($admin->can('delete', $second));
        $this->assertFalse($admin->can('restore', $second));
        $this->assertFalse($admin->can('invite', $second));
        $this->actingAs($admin)->get(UserResource::getUrl('edit', ['record' => $second]))->assertForbidden();

        try {
            app(RoleService::class)->syncUserRoles($rep, [CrmRole::SuperAdmin->value], $admin);
            $this->fail('an admin granted super_admin through RoleService');
        } catch (SuperAdminMembershipException) {
            $this->assertFalse($rep->refresh()->isSuperAdmin());
        }

        // super_admin: holds roles.manage
        $this->assertTrue($superAdmin->can('update', $second));
        $this->assertTrue($superAdmin->can('delete', $second));

        Livewire::actingAs($superAdmin)
            ->test(EditUser::class, ['record' => $rep->getRouteKey()])
            ->assertFormFieldExists('roles', fn (Select $field): bool => array_key_exists(CrmRole::SuperAdmin->value, $field->getOptions()))
            ->fillForm(['roles' => [CrmRole::SuperAdmin->value]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($rep->refresh()->isSuperAdmin());

        Livewire::actingAs($superAdmin)
            ->test(EditUser::class, ['record' => $second->getRouteKey()])
            ->fillForm(['roles' => [CrmRole::Admin->value], 'status' => UserStatus::Disabled->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $second->refresh();
        $this->assertFalse($second->isSuperAdmin());
        $this->assertSame(UserStatus::Disabled, $second->status);
    }

    #[Test]
    public function the_bulk_delete_skips_super_admins_for_an_admin(): void
    {
        $this->superAdmin();
        $second = $this->superAdmin();
        $admin = $this->admin();
        $rep = $this->salesRep();

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->callTableBulkAction('delete', [$second, $rep]);

        $this->assertNotSoftDeleted('users', ['id' => $second->getKey()]);
        $this->assertSoftDeleted('users', ['id' => $rep->getKey()]);
    }

    #[Test]
    public function a_user_in_a_deactivated_team_keeps_the_team_on_an_unrelated_edit_while_new_assignments_offer_active_teams(): void
    {
        $admin = $this->admin();
        $dormant = $this->makeTeam('Dormant Team', 'فريق خامل');
        $active = $this->makeTeam('Active Team', 'فريق نشط');
        $member = $this->makeUser(CrmRole::SalesRep, ['name' => 'Team Member'], $dormant);
        $other = $this->salesRep();
        $dormant->update(['is_active' => false]);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $member->getRouteKey()])
            ->fillForm(['name' => 'Renamed Member'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($dormant->getKey(), $member->refresh()->team_id);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $other->getRouteKey()])
            ->fillForm(['team_id' => $dormant->getKey()])
            ->call('save')
            ->assertHasFormErrors(['team_id']);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $other->getRouteKey()])
            ->fillForm(['team_id' => $active->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($active->getKey(), $other->refresh()->team_id);
    }

    #[Test]
    public function a_user_cannot_delete_their_own_account_but_an_admin_can_delete_another(): void
    {
        $admin = $this->admin();
        $other = $this->salesRep();

        $this->assertFalse($admin->can('delete', $admin));
        $this->assertTrue($admin->can('delete', $other));

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $other->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted('users', ['id' => $other->getKey()]);
    }

    #[Test]
    public function the_last_super_admin_cannot_be_deleted(): void
    {
        $superAdmin = $this->superAdmin();
        $admin = $this->admin();

        $roleAdministrator = $this->userWithPermissions(null, [Permission::UsersManage, Permission::RolesManage]);

        $this->assertFalse($admin->can('delete', $superAdmin));
        $this->assertFalse($roleAdministrator->can('delete', $superAdmin), 'the last active super admin is never deleted');

        $second = $this->superAdmin();
        $this->assertTrue($roleAdministrator->can('delete', $second));
        $this->assertTrue($superAdmin->can('delete', $second));
        $this->assertFalse($admin->can('delete', $second), 'a super admin is deleted only by a roles.manage holder (A-12)');
    }

    #[Test]
    public function the_email_is_read_only_after_creation(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['email' => 'fixed@example.com']);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $target->getRouteKey()])
            ->assertFormFieldDisabled('email')
            ->fillForm(['roles' => [CrmRole::ReadOnly->value], 'name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $target->refresh();

        $this->assertSame('fixed@example.com', $target->email);
        $this->assertSame('Renamed', $target->name);
    }
}
