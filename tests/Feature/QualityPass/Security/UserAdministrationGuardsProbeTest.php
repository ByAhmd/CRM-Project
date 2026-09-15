<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Security;

use App\Enums\CrmRole;
use App\Enums\UserStatus;
use App\Exceptions\Access\SuperAdminMembershipException;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Services\Access\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Security probes: user administration (D-3, D-11, A-12, docs/PERMISSIONS.md).
 *
 * `roles.manage` is reserved to super admins, yet the user form lets any
 * `users.manage` holder hand out every role — super_admin included — and
 * change or remove existing super admins. The last-super-admin guard only
 * refuses the Disabled status, and nobody is kept from switching off their
 * own account.
 */
final class UserAdministrationGuardsProbeTest extends TestCase
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
    public function an_admin_without_roles_manage_cannot_promote_themselves_to_super_admin(): void
    {
        $this->superAdmin();
        $admin = $this->admin();

        $this->assertFalse($admin->can('roles.manage'), 'precondition: admin does not hold roles.manage');

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->fillForm(['roles' => [CrmRole::Admin->value, CrmRole::SuperAdmin->value]])
            ->call('save');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = $admin->fresh();
        $this->assertInstanceOf(User::class, $admin);

        $this->assertFalse($admin->isSuperAdmin(), 'a users.manage holder granted themselves super_admin and with it roles.manage');
        $this->assertFalse($admin->can('roles.manage'));
    }

    #[Test]
    public function an_admin_without_roles_manage_cannot_invite_a_super_admin(): void
    {
        Notification::fake();
        $this->superAdmin();
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Accomplice',
                'email' => 'accomplice@example.com',
                'locale' => 'en',
                'roles' => [CrmRole::SuperAdmin->value],
            ])
            ->call('create');

        $created = User::query()->where('email', 'accomplice@example.com')->first();

        $this->assertFalse($created instanceof User && $created->isSuperAdmin(), 'an admin invited a new super admin');
    }

    #[Test]
    public function an_admin_cannot_demote_another_super_admin(): void
    {
        $this->superAdmin();
        $second = $this->superAdmin();
        $admin = $this->admin();

        // The edit page of a super admin is closed to a users.manage holder
        // without roles.manage (UserPolicy::update), so the form cannot even
        // be filled; the service refuses the same demotion on any other path.
        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $second->getRouteKey()])
            ->assertForbidden();

        try {
            app(RoleService::class)->syncUserRoles($second, [CrmRole::ReadOnly->value], $admin);
            $this->fail('RoleService let an admin without roles.manage strip super_admin');
        } catch (SuperAdminMembershipException) {
            // expected
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($second->fresh()?->isSuperAdmin() ?? false, 'an admin stripped super_admin from a super admin');
    }

    #[Test]
    public function the_last_active_super_admin_cannot_be_set_back_to_pending(): void
    {
        $superAdmin = $this->superAdmin();

        Livewire::actingAs($superAdmin)
            ->test(EditUser::class, ['record' => $superAdmin->getRouteKey()])
            ->fillForm(['status' => UserStatus::Pending->value])
            ->call('save');

        $this->assertSame(
            UserStatus::Active,
            $superAdmin->fresh()?->status,
            'Pending also fails canAccessPanel(): the last super admin is locked out',
        );
    }

    #[Test]
    public function nobody_can_disable_their_own_account(): void
    {
        $this->superAdmin();
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->fillForm(['status' => UserStatus::Disabled->value])
            ->call('save');

        $this->assertSame(
            UserStatus::Active,
            $admin->fresh()?->status,
            'docs/PERMISSIONS.md: nobody may delete or disable their own account',
        );
    }
}
