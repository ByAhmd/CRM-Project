<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Enums\WorkspacePanel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Who enters which panel, over real HTTP (decision D-23): every seeded
 * role, a user with no role, a disabled account and a guest against the
 * home panel and the four workspaces. One case per role — each case runs in
 * a fresh application, so no session or current panel leaks from one user
 * to the next.
 *
 * Expected: the home panel admits every active user; a workspace admits a
 * user holding its `workspace.*` key and answers 403 to everyone else —
 * except the bare /admin, the dashboard every user landed on before D-23,
 * which sends a user without Administration to the sales dashboard (when
 * they may enter Sales) or the workspace screen.
 */
final class PanelAccessMatrixTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const array ALL = ['sales', 'tasks', 'reports', 'admin'];

    private const array SALES_TASKS_REPORTS = ['sales', 'tasks', 'reports'];

    /**
     * @return array<string, array{0: ?string, 1: list<string>}>
     */
    public static function roles(): array
    {
        return [
            'super admin' => [CrmRole::SuperAdmin->value, self::ALL],
            'admin' => [CrmRole::Admin->value, self::ALL],
            'sales manager' => [CrmRole::SalesManager->value, self::SALES_TASKS_REPORTS],
            'sales rep' => [CrmRole::SalesRep->value, self::SALES_TASKS_REPORTS],
            'support' => [CrmRole::Support->value, self::SALES_TASKS_REPORTS],
            'read only' => [CrmRole::ReadOnly->value, self::SALES_TASKS_REPORTS],
            'employee' => [CrmRole::Employee->value, ['tasks']],
            'no role' => [null, []],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
    }

    /**
     * @param  list<string>  $enterable
     */
    #[Test]
    #[DataProvider('roles')]
    public function each_role_enters_exactly_the_workspaces_it_holds(?string $role, array $enterable): void
    {
        $user = $role === null ? $this->userWithPermissions(null, []) : $this->makeUser(CrmRole::from($role));

        $this->actingAs($user)->get('/'.WorkspacePanel::HOME)->assertOk();

        foreach (WorkspacePanel::ordered() as $workspace) {
            $response = $this->actingAs($user)->get('/'.$workspace->path());

            if (in_array($workspace->id(), $enterable, true)) {
                $response->assertOk();

                continue;
            }

            if ($workspace === WorkspacePanel::Admin) {
                // The former dashboard: to the sales dashboard when Sales is
                // enterable, else to the workspace screen.
                $response->assertRedirect('/'.(in_array(WorkspacePanel::Sales->id(), $enterable, true) ? WorkspacePanel::Sales->path() : WorkspacePanel::HOME));

                continue;
            }

            $response->assertForbidden();
        }
    }

    #[Test]
    public function a_guest_is_sent_to_the_home_sign_in_page_from_every_panel(): void
    {
        foreach (['/'.WorkspacePanel::HOME, ...array_map(static fn (WorkspacePanel $workspace): string => '/'.$workspace->path(), WorkspacePanel::ordered())] as $path) {
            $this->get($path)->assertRedirect('/'.WorkspacePanel::HOME.'/login');
        }
    }

    #[Test]
    public function a_guest_deep_link_into_a_workspace_is_remembered_for_after_sign_in(): void
    {
        $this->get('/'.WorkspacePanel::Tasks->path().'/tasks-board')->assertRedirect('/'.WorkspacePanel::HOME.'/login');

        $this->assertSame(url('/'.WorkspacePanel::Tasks->path().'/tasks-board'), session('url.intended'));
    }

    #[Test]
    public function a_disabled_account_is_refused_by_every_panel(): void
    {
        $user = User::factory()->disabled()->create();
        $user->syncRoles([CrmRole::SuperAdmin->value]);

        $this->actingAs($user)->get('/'.WorkspacePanel::HOME)->assertForbidden();

        foreach ([WorkspacePanel::Sales, WorkspacePanel::Tasks, WorkspacePanel::Reports] as $workspace) {
            $this->actingAs($user)->get('/'.$workspace->path())->assertForbidden();
        }
    }

    #[Test]
    public function the_workspace_key_gates_the_panel_and_not_the_page_permission(): void
    {
        // Holds what the general-settings page needs, but not the workspace.
        $without = $this->userWithPermissions(null, [Permission::SettingsManage]);

        $this->actingAs($without)->get('/'.WorkspacePanel::Admin->path().'/general-settings')->assertForbidden();

        $with = $this->userWithPermissions(null, [Permission::SettingsManage, Permission::WorkspaceAdmin]);

        $this->actingAs($with)->get('/'.WorkspacePanel::Admin->path().'/general-settings')->assertOk();
    }

    #[Test]
    public function inside_a_workspace_every_page_keeps_its_own_permission(): void
    {
        // Enters Administration but holds no administration key: the home
        // opens (empty), the screens still answer 403.
        $user = $this->userWithPermissions(null, [Permission::WorkspaceAdmin]);

        $this->actingAs($user)->get('/'.WorkspacePanel::Admin->path())->assertOk();
        $this->actingAs($user)->get('/'.WorkspacePanel::Admin->path().'/users')->assertForbidden();
        $this->actingAs($user)->get('/'.WorkspacePanel::Admin->path().'/general-settings')->assertForbidden();
    }
}
