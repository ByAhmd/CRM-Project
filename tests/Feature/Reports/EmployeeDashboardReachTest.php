<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Filament\Pages\Reports\EmployeeDashboardPage;
use App\Models\User;
use App\Services\Statistics\Reports\EmployeeDashboard;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Who sees whom on the employee dashboard (decision D-26): the page opens
 * for `reports.view` holders inside the Reports workspace, and the employee
 * list and every dashboard follow the reader's task visibility (D-4) — all:
 * everyone, deleted and disabled accounts included and marked; team: their
 * team; own: themselves. An employee outside that reach, a forged or a
 * malformed `?employee=` answers 404 — never a blank or partial page — and
 * the employee role, outside the Reports workspace (D-23), gets 403.
 *
 * Fixture: team Riyadh holds the viewer (for the team and own roles), a
 * sales rep, an employee and a disabled rep; an outsider rep has no team;
 * a deleted rep, outside the team, still carries a task. Each role is its
 * own data-provider case: Filament's current panel is a scoped binding one
 * role's requests would otherwise leak into the next.
 */
final class EmployeeDashboardReachTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const EVERYONE = ['self', 'teammate', 'team_employee', 'disabled_teammate', 'outsider', 'deleted'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function readers(): array
    {
        return [
            'super admin reaches everyone' => ['super_admin', self::EVERYONE],
            'admin reaches everyone' => ['admin', self::EVERYONE],
            'support reaches everyone' => ['support', self::EVERYONE],
            'read only reaches everyone' => ['read_only', self::EVERYONE],
            'sales manager reaches the team' => ['sales_manager', ['self', 'teammate', 'team_employee', 'disabled_teammate']],
            'sales rep reaches only themselves' => ['sales_rep', ['self']],
        ];
    }

    #[Test]
    #[DataProvider('readers')]
    public function each_role_opens_exactly_the_dashboards_inside_its_task_visibility(string $role, array $reached): void
    {
        $people = $this->people(CrmRole::from($role));
        $viewer = $people['self'];

        $this->actingAs($viewer)
            ->get(EmployeeDashboardPage::getUrl())
            ->assertOk()
            ->assertSee(__('reports.pages.employee.title'))
            ->assertSee(__('reports.employee.empty.choose.heading'));

        foreach ($people as $key => $person) {
            $response = $this->actingAs($viewer)->get(EmployeeDashboardPage::getUrl(['employee' => $person->getKey()]));

            if (in_array($key, $reached, true)) {
                $response->assertOk()->assertSee(__('reports.employee.sections.now'));
            } else {
                $response->assertNotFound()->assertDontSee(__('reports.employee.sections.now'));
            }
        }

        $ids = array_map(static fn (string $key): int => (int) $people[$key]->getKey(), $reached);
        $listed = array_map('intval', app(EmployeeDashboard::class)->employees($viewer)->pluck('users.id')->all());

        foreach ($people as $key => $person) {
            $this->assertSame(in_array($key, $reached, true), in_array((int) $person->getKey(), $listed, true), "{$role} list: {$key}");
        }

        $options = $this->employeeOptions($viewer);

        $this->assertEqualsCanonicalizing($listed, array_keys($options), 'the select offers exactly the reach');

        foreach ($ids as $id) {
            $this->assertArrayHasKey($id, $options);
        }
    }

    #[Test]
    #[DataProvider('readers')]
    public function a_forged_or_malformed_employee_in_the_address_is_refused_whole(string $role, array $reached): void
    {
        $people = $this->people(CrmRole::from($role));
        $viewer = $people['self'];
        $page = EmployeeDashboardPage::getUrl();

        $this->actingAs($viewer)->get($page.'?employee=999999')->assertNotFound();
        $this->actingAs($viewer)->get($page.'?employee=abc')->assertNotFound();
        $this->actingAs($viewer)->get($page.'?employee=1.5')->assertNotFound();
        $this->actingAs($viewer)->get($page.'?employee[]='.$viewer->getKey())->assertNotFound();

        if (! in_array('outsider', $reached, true)) {
            $this->actingAs($viewer)->get($page.'?employee='.$people['outsider']->getKey())
                ->assertNotFound()
                ->assertDontSee($people['outsider']->name);

            $this->expectException(AuthorizationException::class);
            app(EmployeeDashboard::class)->snapshot($viewer, $people['outsider']);
        }
    }

    #[Test]
    public function the_employee_role_never_enters_the_page_nor_another_employees_dashboard(): void
    {
        $team = $this->makeTeam();
        $employee = $this->makeUser(CrmRole::Employee, ['name' => 'Employee One'], $team);
        $colleague = $this->makeUser(CrmRole::Employee, ['name' => 'Employee Two'], $team);

        $this->actingAs($employee)->get(EmployeeDashboardPage::getUrl())->assertForbidden();
        $this->actingAs($employee)->get(EmployeeDashboardPage::getUrl(['employee' => $colleague->getKey()]))->assertForbidden();
        $this->actingAs($employee)->get(EmployeeDashboardPage::getUrl(['employee' => $employee->getKey()]))->assertForbidden();
    }

    #[Test]
    public function the_page_is_refused_without_the_reports_permission_inside_the_workspace(): void
    {
        $viewer = $this->userWithPermissions(null, [Permission::WorkspaceReports, Permission::TaskViewAny, Permission::TaskViewAll]);

        $this->actingAs($viewer);
        $this->assertFalse(EmployeeDashboardPage::canAccess());
        $this->get(EmployeeDashboardPage::getUrl())->assertForbidden();
    }

    #[Test]
    public function deleted_disabled_and_pending_accounts_are_marked_in_the_list(): void
    {
        $people = $this->people(CrmRole::Admin);
        $pending = $this->makeUser(CrmRole::SalesRep, ['name' => 'Pending Rep', 'status' => UserStatus::Pending]);

        $options = $this->employeeOptions($people['self']);

        $this->assertSame(__('reports.employee.options.deleted', ['name' => 'Deleted Rep']), $options[$people['deleted']->getKey()]);
        $this->assertSame(__('reports.employee.options.disabled', ['name' => 'Disabled Rep']), $options[$people['disabled_teammate']->getKey()]);
        $this->assertSame(__('reports.employee.options.pending', ['name' => 'Pending Rep']), $options[$pending->getKey()]);
        $this->assertSame('Outsider Rep', $options[$people['outsider']->getKey()]);
    }

    #[Test]
    public function choosing_an_employee_navigates_to_their_address_and_the_shown_employee_is_locked(): void
    {
        $people = $this->people(CrmRole::SalesManager);
        $manager = $people['self'];

        Livewire::actingAs($manager)
            ->test(EmployeeDashboardPage::class)
            ->set('data.employee', (string) $people['teammate']->getKey())
            ->assertRedirect(EmployeeDashboardPage::getUrl(['employee' => $people['teammate']->getKey()]));

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($manager)
            ->withQueryParams(['employee' => (string) $people['teammate']->getKey()])
            ->test(EmployeeDashboardPage::class)
            ->assertSet('employeeId', (int) $people['teammate']->getKey())
            ->set('employeeId', (int) $people['outsider']->getKey());
    }

    /**
     * The viewer of the role and the people around them, by fixture key.
     *
     * @return array<string, User>
     */
    private function people(CrmRole $role): array
    {
        $team = $this->makeTeam();
        $viewer = $this->makeUser($role, ['name' => 'Viewer '.$role->value], $team);

        $deleted = $this->makeUser(CrmRole::SalesRep, ['name' => 'Deleted Rep']);
        $this->taskAssignedTo($deleted, TaskStatus::Pending);
        $deleted->delete();

        return [
            'self' => $viewer,
            'teammate' => $this->makeUser(CrmRole::SalesRep, ['name' => 'Team Rep'], $team),
            'team_employee' => $this->makeUser(CrmRole::Employee, ['name' => 'Team Employee'], $team),
            'disabled_teammate' => $this->makeUser(CrmRole::SalesRep, ['name' => 'Disabled Rep', 'status' => UserStatus::Disabled], $team),
            'outsider' => $this->makeUser(CrmRole::SalesRep, ['name' => 'Outsider Rep']),
            'deleted' => $deleted,
        ];
    }

    /**
     * @return array<int|string, string>
     */
    private function employeeOptions(User $viewer): array
    {
        $page = Livewire::actingAs($viewer)->test(EmployeeDashboardPage::class)->instance();
        $this->assertInstanceOf(EmployeeDashboardPage::class, $page);

        $select = $page->form->getFlatFields(withHidden: true)['employee'] ?? null;
        $this->assertInstanceOf(Select::class, $select);

        return $select->getOptions();
    }
}
