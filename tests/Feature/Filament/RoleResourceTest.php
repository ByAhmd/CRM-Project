<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Pages\RolePermissionsState;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class RoleResourceTest extends TestCase
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
    public function only_super_admins_reach_the_roles_screen(): void
    {
        $superAdmin = $this->superAdmin();
        $admin = $this->admin();

        $this->actingAs($superAdmin)->get(RoleResource::getUrl('index'))->assertOk();
        $this->actingAs($admin)->get(RoleResource::getUrl('index'))->assertForbidden();

        Livewire::actingAs($superAdmin)
            ->test(ListRoles::class)
            ->assertCanSeeTableRecords(Role::query()->get());
    }

    #[Test]
    public function a_super_admin_creates_a_custom_role_with_permissions(): void
    {
        $superAdmin = $this->superAdmin();

        Livewire::actingAs($superAdmin)
            ->test(CreateRole::class)
            ->fillForm([
                'name' => 'regional_manager',
                'name_ar' => 'مدير إقليمي',
                'name_en' => 'Regional manager',
                'permissions' => [
                    'lead' => [Permission::LeadViewAny->value, Permission::LeadViewTeam->value, Permission::LeadAssign->value],
                    'reports' => [Permission::ReportsView->value],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::query()->where('name', 'regional_manager')->firstOrFail();

        $this->assertSame('مدير إقليمي', $role->name_ar);
        $this->assertEqualsCanonicalizing(
            [Permission::LeadViewAny->value, Permission::LeadViewTeam->value, Permission::LeadAssign->value, Permission::ReportsView->value],
            $role->permissions()->pluck('name')->all(),
        );
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::RolePermissionsChanged->value, 'subject_id' => $role->getKey()]);
    }

    #[Test]
    public function the_role_key_must_be_a_machine_name(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(CreateRole::class)
            ->fillForm(['name' => 'Regional Manager', 'name_ar' => 'مدير', 'name_en' => 'Manager'])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    #[Test]
    public function editing_a_seeded_role_changes_permissions_but_keeps_the_key(): void
    {
        $superAdmin = $this->superAdmin();
        $role = Role::query()->where('name', CrmRole::ReadOnly->value)->firstOrFail();

        Livewire::actingAs($superAdmin)
            ->test(EditRole::class, ['record' => $role->getRouteKey()])
            ->assertFormFieldDisabled('name')
            ->fillForm([
                'name_en' => 'Viewer',
                'permissions' => RolePermissionsState::group([Permission::LeadViewAny->value]),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $role->refresh();

        $this->assertSame(CrmRole::ReadOnly->value, $role->name);
        $this->assertSame('Viewer', $role->name_en);
        $this->assertSame([Permission::LeadViewAny->value], $role->permissions()->pluck('name')->all());
    }

    #[Test]
    public function the_super_admin_role_is_locked(): void
    {
        $superAdmin = $this->superAdmin();
        $locked = Role::query()->where('name', CrmRole::SuperAdmin->value)->firstOrFail();

        $this->assertFalse($superAdmin->can('update', $locked));
        $this->assertFalse($superAdmin->can('delete', $locked));
        $this->actingAs($superAdmin)->get(RoleResource::getUrl('edit', ['record' => $locked]))->assertForbidden();
    }

    #[Test]
    public function seeded_roles_cannot_be_deleted_from_the_list(): void
    {
        $superAdmin = $this->superAdmin();
        $seeded = Role::query()->where('name', CrmRole::Support->value)->firstOrFail();
        $custom = Role::query()->create(['name' => 'temp_role', 'guard_name' => 'web', 'name_ar' => 'مؤقت', 'name_en' => 'Temporary']);

        Livewire::actingAs($superAdmin)
            ->test(ListRoles::class)
            ->assertTableActionHidden('delete', $seeded)
            ->assertTableActionVisible('delete', $custom)
            ->callTableAction('delete', $custom);

        $this->assertDatabaseMissing('roles', ['name' => 'temp_role']);
        $this->assertDatabaseHas('roles', ['name' => CrmRole::Support->value]);
    }
}
