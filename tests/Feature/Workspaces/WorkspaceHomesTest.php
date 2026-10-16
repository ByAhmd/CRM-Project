<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\Permission;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Enums\WorkspacePanel;
use App\Filament\Pages\AdminHome;
use App\Filament\Pages\ReportsHome;
use App\Filament\Pages\SalesDashboard;
use App\Filament\Pages\Settings\GeneralSettings;
use App\Filament\Pages\System\Backups;
use App\Filament\Pages\TasksDashboard;
use App\Filament\Resources\LeadSources\LeadSourceResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\SalesKpisWidget;
use App\Filament\Widgets\UpcomingFollowUpsWidget;
use App\Support\Filament\PanelUrl;
use App\Support\Filament\WorkspacePanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The home page of each workspace (decision D-23): the sales dashboard,
 * «عملي» (My Work, D-27), the list of reports and the list of administration
 * screens — the last two offering only what the viewer may open, so no
 * link on a home answers 403.
 */
final class WorkspaceHomesTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
    }

    #[Test]
    public function the_sales_home_is_the_sales_dashboard_without_the_task_lists(): void
    {
        $rep = $this->salesRep();

        $this->actingAs($rep)
            ->get(PanelUrl::panelHome(WorkspacePanel::Sales))
            ->assertOk()
            ->assertSee(__('dashboard.title'))
            ->assertSee(__('dashboard.filters.period'));

        $widgets = (new SalesDashboard)->getWidgets();

        $this->assertContains(SalesKpisWidget::class, $widgets);
        $this->assertNotContains(UpcomingFollowUpsWidget::class, $widgets);
    }

    #[Test]
    public function the_tasks_home_is_my_work_with_the_follow_ups_under_it(): void
    {
        $employee = $this->employee();

        // D-27: the Tasks home is «عملي» (My Work).
        $this->actingAs($employee)
            ->get(PanelUrl::panelHome(WorkspacePanel::Tasks))
            ->assertOk()
            ->assertSee(__('my_work.pages.title'))
            ->assertDontSee(__('dashboard.filters.period'));

        $this->assertSame([UpcomingFollowUpsWidget::class], (new TasksDashboard)->getWidgets());

        // The follow-ups list shows only while a follow-up, call or meeting is coming.
        $this->actingAs($employee);
        $this->assertFalse(UpcomingFollowUpsWidget::canView());

        $this->taskAssignedTo($employee, TaskStatus::Pending, ['kind' => TaskKind::Call, 'due_at' => now()->addDay()]);
        $this->assertTrue(UpcomingFollowUpsWidget::canView());
    }

    #[Test]
    public function the_reports_home_lists_every_report_a_reader_may_open(): void
    {
        $response = $this->actingAs($this->salesRep())->get(ReportsHome::getUrl())->assertOk();

        foreach (WorkspacePanelRegistry::reportPages() as $report) {
            $response->assertSee('href="'.$report::getUrl().'"', escape: false);
        }
    }

    #[Test]
    public function the_reports_home_says_so_when_no_report_is_open_to_the_viewer(): void
    {
        $this->actingAs($this->userWithPermissions(null, [Permission::WorkspaceReports]))
            ->get(ReportsHome::getUrl())
            ->assertOk()
            ->assertSee(__('workspaces.empty.reports_home.heading'))
            ->assertDontSee('href="'.url('/reports/leads').'"', escape: false);
    }

    #[Test]
    public function the_administration_home_offers_only_the_screens_the_viewer_may_open(): void
    {
        // An administrator holds everything but role administration (and so backups).
        $this->actingAs($this->admin())
            ->get(AdminHome::getUrl())
            ->assertOk()
            ->assertSee('href="'.UserResource::getUrl().'"', escape: false)
            ->assertSee('href="'.GeneralSettings::getUrl().'"', escape: false)
            ->assertSee('href="'.LeadSourceResource::getUrl().'"', escape: false)
            ->assertDontSee('href="'.RoleResource::getUrl().'"', escape: false)
            ->assertDontSee('href="'.Backups::getUrl().'"', escape: false);

        $this->actingAs($this->superAdmin())
            ->get(AdminHome::getUrl())
            ->assertOk()
            ->assertSee('href="'.RoleResource::getUrl().'"', escape: false)
            ->assertSee('href="'.Backups::getUrl().'"', escape: false);
    }

    #[Test]
    public function every_link_on_the_administration_home_opens_for_its_viewer(): void
    {
        $viewer = $this->userWithPermissions(null, [Permission::WorkspaceAdmin, Permission::SettingsManage]);
        $html = (string) $this->actingAs($viewer)->get(AdminHome::getUrl())->assertOk()->getContent();

        preg_match_all('/<a href="([^"]+)"[^>]*class="crm-workspace-link"/', $html, $matches);

        $this->assertNotEmpty($matches[1], 'sanity: the home lists the settings screens');
        $this->assertNotContains(UserResource::getUrl(), $matches[1]);

        foreach ($matches[1] as $url) {
            $this->actingAs($viewer)->get(html_entity_decode($url))->assertOk();
        }
    }
}
