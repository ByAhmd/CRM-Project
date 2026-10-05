<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Enums\WorkspacePanel;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Role;
use App\Services\Access\PanelAccess;
use App\Support\Access\RolePermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The four workspace keys (decision D-23): the seeded defaults, the data
 * migration that grants them to the roles an install already has (seeded
 * roles by their defaults, custom roles by what they already hold), the
 * Roles editor's «مساحات العمل» section, and the rule PanelAccess applies —
 * which fails closed and is re-checked on every Livewire request.
 */
final class WorkspacePermissionsTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const string MIGRATION = '2026_10_05_100001_grant_workspace_permissions_to_existing_roles.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
    }

    #[Test]
    public function the_seeded_roles_enter_the_d23_workspaces(): void
    {
        $expected = [
            CrmRole::SuperAdmin->value => ['sales', 'tasks', 'reports', 'admin'],
            CrmRole::Admin->value => ['sales', 'tasks', 'reports', 'admin'],
            CrmRole::SalesManager->value => ['sales', 'tasks', 'reports'],
            CrmRole::SalesRep->value => ['sales', 'tasks', 'reports'],
            CrmRole::Support->value => ['sales', 'tasks', 'reports'],
            CrmRole::ReadOnly->value => ['sales', 'tasks', 'reports'],
            CrmRole::Employee->value => ['tasks'],
        ];

        foreach ($expected as $role => $workspaces) {
            $matrix = array_values(array_filter(
                RolePermissionMatrix::for(CrmRole::from($role)),
                static fn (Permission $permission): bool => $permission->group() === 'workspace',
            ));

            $this->assertSame($workspaces, array_map(static fn (Permission $permission): string => $permission->verb(), $matrix), $role);

            $user = $this->makeUser(CrmRole::from($role));
            $this->assertSame($workspaces, array_map(
                static fn (WorkspacePanel $workspace): string => $workspace->id(),
                app(PanelAccess::class)->enterableWorkspaces($user),
            ), $role);
        }
    }

    #[Test]
    public function the_workspace_keys_are_labelled_in_both_languages_and_come_first(): void
    {
        $this->assertSame('workspace', array_key_first(Permission::grouped()));
        $this->assertSame(Permission::workspaces(), Permission::grouped()['workspace']);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach (Permission::workspaces() as $permission) {
                $this->assertSame(WorkspacePanel::from($permission->verb())->getLabel(), $permission->label(), "{$locale}: {$permission->value}");
            }
        }

        app()->setLocale('ar');
        $this->assertSame('مساحات العمل', Permission::WorkspaceSales->groupLabel());
    }

    #[Test]
    public function the_migration_grants_existing_roles_by_their_defaults_or_by_what_they_hold(): void
    {
        $this->revokeWorkspaceKeysFromEveryRole();

        $salesAndAdmin = $this->customRole('regional_manager', [Permission::LeadViewAny, Permission::SettingsManage]);
        $tasksOnly = $this->customRole('field_staff', [Permission::TaskViewAny, Permission::AttachmentDownload]);
        $reportsAndExports = $this->customRole('analyst', [Permission::ReportsView, Permission::TaskExport]);
        $routines = $this->customRole('dispatcher', [Permission::TaskAssign]);
        $auditor = $this->customRole('auditor', [Permission::AuditView]);
        $filesOnly = $this->customRole('archivist', [Permission::AttachmentDownload]);

        $this->runMigration('up');

        $this->assertSame(['workspace.sales', 'workspace.admin'], $this->workspaceKeys($salesAndAdmin));
        $this->assertSame(['workspace.tasks'], $this->workspaceKeys($tasksOnly));
        // `task.export` acts on the task list (Tasks) and opens the export history (Sales).
        $this->assertSame(['workspace.sales', 'workspace.tasks', 'workspace.reports'], $this->workspaceKeys($reportsAndExports));
        $this->assertSame(['workspace.tasks'], $this->workspaceKeys($routines));
        $this->assertSame(['workspace.admin'], $this->workspaceKeys($auditor));
        $this->assertSame([], $this->workspaceKeys($filesOnly));

        $this->assertSame(['workspace.sales', 'workspace.tasks', 'workspace.reports'], $this->workspaceKeys($this->seededRole(CrmRole::SalesRep)));
        $this->assertSame(['workspace.tasks'], $this->workspaceKeys($this->seededRole(CrmRole::Employee)));
        $this->assertSame(['workspace.sales', 'workspace.tasks', 'workspace.reports', 'workspace.admin'], $this->workspaceKeys($this->seededRole(CrmRole::Admin)));

        // A second run changes nothing.
        $this->runMigration('up');
        $this->assertSame(['workspace.sales', 'workspace.admin'], $this->workspaceKeys($salesAndAdmin));
    }

    #[Test]
    public function the_migration_is_reversible(): void
    {
        $this->runMigration('down');

        $this->assertSame(0, PermissionModel::query()->where('name', 'like', 'workspace.%')->count());

        $this->runMigration('up');

        $this->assertSame(4, PermissionModel::query()->where('name', 'like', 'workspace.%')->count());
        $this->assertSame(['workspace.tasks'], $this->workspaceKeys($this->seededRole(CrmRole::Employee)));
    }

    #[Test]
    public function the_roles_editor_shows_the_workspaces_section_before_the_permissions_and_saves_it(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(CreateRole::class)
            ->assertSee('مساحات العمل')
            ->fillForm([
                'name' => 'field_staff',
                'name_ar' => 'فريق ميداني',
                'name_en' => 'Field staff',
                'permissions' => [
                    'workspace' => [Permission::WorkspaceTasks->value],
                    'task' => [Permission::TaskViewAny->value],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::query()->where('name', 'field_staff')->firstOrFail();

        $this->assertEqualsCanonicalizing([Permission::WorkspaceTasks->value, Permission::TaskViewAny->value], $role->permissions()->pluck('name')->all());

        $html = Livewire::actingAs($this->superAdmin())->test(CreateRole::class)->html();
        $this->assertLessThan(strpos($html, 'الصلاحيات'), strpos($html, 'مساحات العمل'));
    }

    #[Test]
    public function an_unknown_panel_is_refused_and_a_disabled_account_enters_nothing(): void
    {
        $admin = $this->superAdmin();

        $this->assertFalse(app(PanelAccess::class)->canAccess($admin, 'platform'));
        $this->assertTrue(app(PanelAccess::class)->canAccess($admin, WorkspacePanel::HOME));

        $admin->forceFill(['status' => UserStatus::Disabled])->save();

        $this->assertFalse(app(PanelAccess::class)->canAccess($admin, WorkspacePanel::HOME));
        $this->assertSame([], app(PanelAccess::class)->enterableWorkspaces($admin));
    }

    #[Test]
    public function a_workspace_revoked_while_a_page_is_open_refuses_the_next_request_from_it(): void
    {
        $user = $this->userWithPermissions(null, [Permission::TaskViewAny, Permission::WorkspaceTasks]);
        $snapshot = $this->firstLivewireSnapshot($user, TaskResource::getUrl());

        $this->livewireUpdate($snapshot)->assertOk();

        $user->revokePermissionTo(Permission::WorkspaceTasks->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->livewireUpdate($snapshot)->assertForbidden();
    }

    private function runMigration(string $direction): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        $this->assertInstanceOf(Migration::class, $migration);

        $migration->{$direction}();
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function customRole(string $name, array $permissions): Role
    {
        /** @var Role $role */
        $role = Role::query()->create(['name' => $name, 'guard_name' => 'web', 'name_ar' => $name, 'name_en' => $name]);
        $role->syncPermissions(array_map(static fn (Permission $permission): string => $permission->value, $permissions));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role;
    }

    private function seededRole(CrmRole $role): Role
    {
        return Role::query()->where('name', $role->value)->firstOrFail();
    }

    private function revokeWorkspaceKeysFromEveryRole(): void
    {
        foreach (Role::query()->get() as $role) {
            foreach (Permission::workspaces() as $permission) {
                if ($role->hasPermissionTo($permission->value)) {
                    $role->revokePermissionTo($permission->value);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    private function workspaceKeys(Role $role): array
    {
        $held = $role->permissions()->pluck('name')->all();

        return array_values(array_filter(
            array_map(static fn (Permission $permission): string => $permission->value, Permission::workspaces()),
            static fn (string $key): bool => in_array($key, $held, true),
        ));
    }
}
