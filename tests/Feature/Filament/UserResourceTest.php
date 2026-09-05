<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\CrmRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        Livewire::actingAs($superAdmin)
            ->test(EditUser::class, ['record' => $superAdmin->getRouteKey()])
            ->fillForm(['status' => UserStatus::Disabled->value])
            ->call('save')
            ->assertNotified();

        $this->assertSame(UserStatus::Active, $superAdmin->refresh()->status);

        Livewire::actingAs($superAdmin)
            ->test(EditUser::class, ['record' => $superAdmin->getRouteKey()])
            ->fillForm(['roles' => [CrmRole::Admin->value]])
            ->call('save')
            ->assertNotified();

        $this->assertTrue($superAdmin->refresh()->isSuperAdmin());
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

        $this->assertFalse($admin->can('delete', $superAdmin));

        $second = $this->superAdmin();
        $this->assertTrue($admin->can('delete', $second));
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
