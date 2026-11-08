<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\ActivityKind;
use App\Enums\CloseReasonKind;
use App\Filament\Pages\SalesDashboard;
use App\Filament\Widgets\ActivityCountsWidget;
use App\Filament\Widgets\LeadsByStatusChart;
use App\Filament\Widgets\PipelineByStageChart;
use App\Filament\Widgets\RevenueWonByMonthChart;
use App\Filament\Widgets\SalesKpisWidget;
use App\Filament\Widgets\StaleDealsWidget;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\Deals\DealCloseService;
use App\Services\Statistics\DashboardFilters;
use App\Services\Statistics\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The sales dashboard is the current project's page (decision D-30): the
 * KPI strip, the two charts, the revenue line, the stale-deals list and the
 * activity chart — activities follow their records — read the viewer's
 * current project only and follow the switcher, and the subheading names the
 * project the figures belong to.
 *
 * Fixture (2026-09-06 12:00 Asia/Riyadh, an admin who sees everything): the
 * default project holds 2 leads, 1 open deal (1,000) and a call logged on 2
 * September; «إيقان» holds 3 leads, 2 open deals (2,000 fresh, 3,000 untouched
 * for 20 days), a deal won in September (7,000) and a meeting logged on 3
 * September.
 */
final class DashboardProjectScopeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $admin;

    private Project $default;

    private Project $iqan;

    private Deal $staleIqanDeal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-09-06 12:00:00', 'Asia/Riyadh'));

        $this->admin = $this->admin();
        $this->default = $this->defaultProject();
        $this->iqan = $this->makeProject();

        $defaultLead = Lead::factory()->create(['owner_id' => $this->admin->getKey()]);
        Lead::factory()->create(['owner_id' => $this->admin->getKey()]);
        $iqanLead = Lead::factory()->create(['owner_id' => $this->admin->getKey(), 'project_id' => $this->iqan->getKey()]);
        Lead::factory()->count(2)->create(['owner_id' => $this->admin->getKey(), 'project_id' => $this->iqan->getKey()]);

        Deal::factory()->create(['owner_id' => $this->admin->getKey(), 'title' => 'Default deal', 'amount' => 1000]);
        Deal::factory()->create(['owner_id' => $this->admin->getKey(), 'title' => 'Fresh Iqan deal', 'amount' => 2000, 'project_id' => $this->iqan->getKey()]);
        $this->staleIqanDeal = Deal::factory()->create(['owner_id' => $this->admin->getKey(), 'title' => 'Forgotten Iqan deal', 'amount' => 3000, 'project_id' => $this->iqan->getKey()]);
        Deal::query()->whereKey($this->staleIqanDeal->getKey())->update(['updated_at' => now()->subDays(20)]);

        app(DealCloseService::class)->win(
            Deal::factory()->create(['owner_id' => $this->admin->getKey(), 'title' => 'Signed Iqan deal', 'amount' => 7000, 'project_id' => $this->iqan->getKey()]),
            $this->reasonOfKind(CloseReasonKind::Won),
            $this->admin,
        );

        Activity::factory()->create(['owner_id' => $this->admin->getKey(), 'lead_id' => $defaultLead->getKey(), 'occurred_at' => '2026-09-02 10:00:00']);
        Activity::factory()->ofKind(ActivityKind::Meeting)->create(['owner_id' => $this->admin->getKey(), 'lead_id' => $iqanLead->getKey(), 'occurred_at' => '2026-09-03 10:00:00']);
    }

    #[Test]
    public function the_kpi_strip_counts_the_current_project_only_and_follows_the_switcher(): void
    {
        $kpis = $this->kpis();

        $this->assertSame(2, $kpis['new_leads']);
        $this->assertSame(1, $kpis['open_deals_count']);
        $this->assertSame(1000.0, $kpis['open_deals_amount']);
        $this->assertSame(0, $kpis['won_count']);
        $this->assertSame(0.0, $kpis['won_amount']);

        $this->useProject($this->admin, $this->iqan);
        $kpis = $this->kpis();

        $this->assertSame(3, $kpis['new_leads']);
        $this->assertSame(2, $kpis['open_deals_count']);
        $this->assertSame(5000.0, $kpis['open_deals_amount']);
        $this->assertSame(1, $kpis['won_count']);
        $this->assertSame(7000.0, $kpis['won_amount']);

        // The services answer the same without the page: the project is read at
        // query time from the viewer, never from the filters DTO.
        $direct = app(DashboardMetrics::class)->salesKpis($this->admin, DashboardFilters::currentMonth());
        $this->assertSame(3, $direct['new_leads']);
        $this->assertSame(7000.0, $direct['won_amount']);
    }

    #[Test]
    public function the_lead_funnel_and_the_pipeline_charts_read_the_current_project_only(): void
    {
        $funnel = Livewire::actingAs($this->admin)->test(LeadsByStatusChart::class, ['pageFilters' => $this->september()])->instance();
        assert($funnel instanceof LeadsByStatusChart);
        $this->assertSame(2, array_sum($funnel->chartData()['datasets'][0]['data']));

        $pipeline = Livewire::actingAs($this->admin)->test(PipelineByStageChart::class, ['pageFilters' => $this->september()])->instance();
        assert($pipeline instanceof PipelineByStageChart);
        $this->assertSame(1000.0, array_sum($pipeline->chartData()['datasets'][0]['data']));

        $this->useProject($this->admin, $this->iqan);

        $funnel = Livewire::actingAs($this->admin)->test(LeadsByStatusChart::class, ['pageFilters' => $this->september()])->instance();
        assert($funnel instanceof LeadsByStatusChart);
        $this->assertSame(3, array_sum($funnel->chartData()['datasets'][0]['data']));

        $pipeline = Livewire::actingAs($this->admin)->test(PipelineByStageChart::class, ['pageFilters' => $this->september()])->instance();
        assert($pipeline instanceof PipelineByStageChart);
        $this->assertSame(5000.0, array_sum($pipeline->chartData()['datasets'][0]['data']));
    }

    #[Test]
    public function the_revenue_line_and_the_stale_deals_read_the_current_project_only(): void
    {
        $revenue = Livewire::actingAs($this->admin)
            ->test(RevenueWonByMonthChart::class, ['pageFilters' => $this->september()])
            ->assertSee(__('dashboard.empty.chart'))
            ->instance();
        assert($revenue instanceof RevenueWonByMonthChart);
        $this->assertSame([], $revenue->chartData(), 'nothing was won in the default project');

        Livewire::actingAs($this->admin)
            ->test(StaleDealsWidget::class, ['pageFilters' => $this->september()])
            ->assertCanNotSeeTableRecords([$this->staleIqanDeal])
            ->assertSee(__('dashboard.empty.stale_deals'));

        $this->useProject($this->admin, $this->iqan);

        $revenue = Livewire::actingAs($this->admin)->test(RevenueWonByMonthChart::class, ['pageFilters' => $this->september()])->instance();
        assert($revenue instanceof RevenueWonByMonthChart);
        $this->assertSame(7000.0, array_sum($revenue->chartData()['datasets'][0]['data']));

        Livewire::actingAs($this->admin)
            ->test(StaleDealsWidget::class, ['pageFilters' => $this->september()])
            ->assertCanSeeTableRecords([$this->staleIqanDeal])
            ->assertSee($this->staleIqanDeal->title);
    }

    #[Test]
    public function the_activity_counts_widget_counts_the_current_projects_records_only(): void
    {
        $call = array_search(ActivityKind::Call, ActivityKind::cases(), true);
        $meeting = array_search(ActivityKind::Meeting, ActivityKind::cases(), true);

        $widget = Livewire::actingAs($this->admin)->test(ActivityCountsWidget::class, ['pageFilters' => $this->september()])->instance();
        assert($widget instanceof ActivityCountsWidget);
        $data = $widget->chartData()['datasets'][0]['data'];

        $this->assertSame(1, array_sum($data), 'the default project: the call on its lead, not the meeting on the «إيقان» lead');
        $this->assertSame(1, $data[$call]);
        $this->assertSame(0, $data[$meeting]);

        $this->useProject($this->admin, $this->iqan);

        $widget = Livewire::actingAs($this->admin)->test(ActivityCountsWidget::class, ['pageFilters' => $this->september()])->instance();
        assert($widget instanceof ActivityCountsWidget);
        $data = $widget->chartData()['datasets'][0]['data'];

        $this->assertSame(1, array_sum($data), '«إيقان»: the meeting on its lead, not the call on the default lead');
        $this->assertSame(0, $data[$call]);
        $this->assertSame(1, $data[$meeting]);
    }

    #[Test]
    public function the_dashboard_names_the_current_project_under_its_title(): void
    {
        $component = Livewire::actingAs($this->admin)->test(SalesDashboard::class);
        $component
            ->assertOk()
            ->assertSee(__('projects.switcher.current'))
            ->assertSee($this->default->display_name)
            ->assertDontSee($this->iqan->display_name);

        $page = $component->instance();
        assert($page instanceof SalesDashboard);
        $this->assertStringContainsString($this->default->display_name, (string) $page->getSubheading());

        $this->useProject($this->admin, $this->iqan);

        $page = Livewire::actingAs($this->admin)
            ->test(SalesDashboard::class)
            ->assertSee(__('projects.switcher.current'))
            ->assertSee($this->iqan->display_name)
            ->instance();
        assert($page instanceof SalesDashboard);
        $this->assertStringContainsString($this->iqan->display_name, (string) $page->getSubheading());
        $this->assertStringContainsString(__('projects.switcher.current'), (string) $page->getSubheading());
    }

    /**
     * The KPI figures as the widget computes them for the admin over September.
     *
     * @return array<string, mixed>
     */
    private function kpis(): array
    {
        $widget = Livewire::actingAs($this->admin)->test(SalesKpisWidget::class, ['pageFilters' => $this->september()])->instance();
        assert($widget instanceof SalesKpisWidget);

        return $widget->kpis();
    }

    /**
     * @return array<string, string>
     */
    private function september(): array
    {
        return ['from' => '2026-09-01', 'to' => '2026-09-30'];
    }
}
