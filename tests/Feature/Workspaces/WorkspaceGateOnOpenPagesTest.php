<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\Permission;
use App\Enums\WorkspacePanel;
use App\Filament\Pages\Reports\LeadReportPage;
use App\Filament\Pages\Settings\GeneralSettings;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Http\Middleware\AuthenticateWorkspace;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource as FilamentResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The workspace gate (D-23) runs again on every Livewire update request an
 * open page sends, in every workspace — not only when the page is loaded.
 *
 * Livewire matches persistent middleware by exact class name, so the gate is
 * covered only because the workspace panels register AuthenticateWorkspace
 * itself as persistent; Filament's own registration of its parent class
 * does not reach it. Each update here starts the way a real request does:
 * the scoped Filament manager forgotten and the current panel set to the
 * home panel, so the panel the update runs in comes from the persistent
 * middleware, never from state the page load left behind.
 */
final class WorkspaceGateOnOpenPagesTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    #[Test]
    public function the_workspace_gate_itself_is_livewire_persistent_middleware(): void
    {
        $this->actingAs($this->salesRep())->get(LeadResource::getUrl())->assertOk();

        $this->assertContains(AuthenticateWorkspace::class, Livewire::getPersistentMiddleware());
    }

    /**
     * @return array<string, array{WorkspacePanel, class-string<FilamentResource>|class-string<Page>, list<Permission>}>
     */
    public static function openPages(): array
    {
        return [
            'sales: the lead list' => [WorkspacePanel::Sales, LeadResource::class, [Permission::LeadViewAny]],
            'tasks: the task list' => [WorkspacePanel::Tasks, TaskResource::class, [Permission::TaskViewAny]],
            'reports: the lead report' => [WorkspacePanel::Reports, LeadReportPage::class, [Permission::ReportsView]],
            'administration: general settings' => [WorkspacePanel::Admin, GeneralSettings::class, [Permission::SettingsManage]],
        ];
    }

    /**
     * @param  class-string<FilamentResource>|class-string<Page>  $page
     * @param  list<Permission>  $permissions
     */
    #[Test]
    #[DataProvider('openPages')]
    public function a_workspace_revoked_while_its_page_is_open_refuses_the_next_request_from_it(WorkspacePanel $workspace, string $page, array $permissions): void
    {
        $user = $this->userWithPermissions(null, [$workspace->permission(), ...$permissions]);
        $snapshot = $this->firstLivewireSnapshot($user, $this->urlOf($page));

        $this->startAFreshRequestCycle();
        $this->livewireUpdate($snapshot)->assertOk();

        $user->revokePermissionTo($workspace->permission()->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->startAFreshRequestCycle();
        $this->livewireUpdate($snapshot)->assertForbidden();
    }

    /**
     * @param  class-string<FilamentResource>|class-string<Page>  $page
     * @param  list<Permission>  $permissions
     */
    #[Test]
    #[DataProvider('openPages')]
    public function a_snapshot_replayed_by_a_user_without_the_workspace_is_refused(WorkspacePanel $workspace, string $page, array $permissions): void
    {
        $holder = $this->userWithPermissions(null, [$workspace->permission(), ...$permissions]);
        $snapshot = $this->firstLivewireSnapshot($holder, $this->urlOf($page));

        // Everything the page needs except the workspace itself.
        $outsider = $this->userWithPermissions(null, $permissions);

        $this->actingAs($outsider);
        $this->startAFreshRequestCycle();
        $this->livewireUpdate($snapshot)->assertForbidden();
    }

    /**
     * @param  class-string<FilamentResource>|class-string<Page>  $page
     */
    private function urlOf(string $page): string
    {
        return $page::getUrl();
    }

    private function startAFreshRequestCycle(): void
    {
        $this->app->forgetScopedInstances();
        Filament::setCurrentPanel(WorkspacePanel::HOME);
    }
}
