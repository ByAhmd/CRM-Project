<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use App\Models\Role;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Decision D-22: routine tasks belong to the people who hand out work. Every
 * ability needs `task.assign` (D-14) — super admins and admins by default, and
 * any role a super admin grants it to; no other seeded role reaches the
 * screen, its pages or a single routine. Nothing is ever restored or
 * force-deleted: a routine has no soft deletes.
 */
final class RoutineTaskPolicyTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const array ABILITIES = ['viewAny', 'view', 'create', 'update', 'pause', 'resume', 'delete', 'deleteAny'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
    }

    /**
     * @return array<string, array{CrmRole, bool}>
     */
    public static function seededRoles(): array
    {
        return [
            'super admin' => [CrmRole::SuperAdmin, true],
            'admin' => [CrmRole::Admin, true],
            'sales manager' => [CrmRole::SalesManager, false],
            'sales rep' => [CrmRole::SalesRep, false],
            'support' => [CrmRole::Support, false],
            'read only' => [CrmRole::ReadOnly, false],
            'employee' => [CrmRole::Employee, false],
        ];
    }

    #[Test]
    #[DataProvider('seededRoles')]
    public function each_seeded_role_manages_routines_only_when_it_may_hand_out_work(CrmRole $role, bool $allowed): void
    {
        $user = $this->makeUser($role);
        $routine = TaskTemplate::factory()->create();

        $this->assertSame($allowed, $user->can(Permission::TaskAssign->value), 'precondition: the matrix grants task.assign to admins only');

        foreach (self::ABILITIES as $ability) {
            $arguments = in_array($ability, ['viewAny', 'create', 'deleteAny'], true) ? TaskTemplate::class : $routine;

            $this->assertSame($allowed, $user->can($ability, $arguments), "{$role->value} {$ability}");
        }

        $this->assertNeverRestoredNorForceDeleted($user, $routine);
    }

    #[Test]
    #[DataProvider('seededRoles')]
    public function each_seeded_role_opens_the_routine_pages_only_when_it_may_hand_out_work(CrmRole $role, bool $allowed): void
    {
        $user = $this->makeUser($role);
        $routine = TaskTemplate::factory()->create();
        $expected = $allowed ? 200 : 403;

        $this->actingAs($user)->get(TaskTemplateResource::getUrl('index'))->assertStatus($expected);
        $this->actingAs($user)->get(TaskTemplateResource::getUrl('create'))->assertStatus($expected);
        $this->actingAs($user)->get(TaskTemplateResource::getUrl('edit', ['record' => $routine]))->assertStatus($expected);

        $this->actingAs($user);
        $this->assertSame($allowed, TaskTemplateResource::canViewAny(), 'the navigation item follows the policy');
    }

    #[Test]
    public function a_custom_role_granted_task_assign_manages_routines(): void
    {
        $dispatcher = $this->userInCustomRole([Permission::TaskViewAny, Permission::TaskAssign]);
        $routine = TaskTemplate::factory()->create();

        foreach (self::ABILITIES as $ability) {
            $arguments = in_array($ability, ['viewAny', 'create', 'deleteAny'], true) ? TaskTemplate::class : $routine;

            $this->assertTrue($dispatcher->can($ability, $arguments), "dispatcher {$ability}");
        }

        $this->assertNeverRestoredNorForceDeleted($dispatcher, $routine);
        $this->actingAs($dispatcher)->get(TaskTemplateResource::getUrl('index'))->assertOk();
    }

    #[Test]
    public function a_custom_role_with_every_task_key_but_assign_is_refused(): void
    {
        $lead = $this->userInCustomRole([
            Permission::TaskViewAny, Permission::TaskViewAll, Permission::TaskCreate, Permission::TaskUpdate, Permission::TaskDelete,
        ]);
        $routine = TaskTemplate::factory()->create();

        foreach (self::ABILITIES as $ability) {
            $arguments = in_array($ability, ['viewAny', 'create', 'deleteAny'], true) ? TaskTemplate::class : $routine;

            $this->assertFalse($lead->can($ability, $arguments), "task lead {$ability}");
        }

        $this->actingAs($lead)->get(TaskTemplateResource::getUrl('index'))->assertForbidden();
        $this->actingAs($lead)->get(TaskTemplateResource::getUrl('edit', ['record' => $routine]))->assertForbidden();
    }

    private function assertNeverRestoredNorForceDeleted(User $user, TaskTemplate $routine): void
    {
        $this->assertFalse($user->can('restore', $routine));
        $this->assertFalse($user->can('restoreAny', TaskTemplate::class));
        $this->assertFalse($user->can('forceDelete', $routine));
        $this->assertFalse($user->can('forceDeleteAny', TaskTemplate::class));
    }

    /**
     * A user in a role a super admin built in the Roles screen (D-3).
     *
     * @param  list<Permission>  $permissions
     */
    private function userInCustomRole(array $permissions): User
    {
        $role = Role::query()->create(['name' => 'custom_'.count($permissions), 'guard_name' => 'web', 'name_ar' => 'دور مخصص', 'name_en' => 'Custom role']);
        $role->givePermissionTo(array_map(static fn (Permission $permission): string => $permission->value, $permissions));

        $user = User::factory()->create();
        $user->syncRoles([$role->name]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh() ?? $user;
    }
}
