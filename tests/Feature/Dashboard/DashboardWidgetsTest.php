<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ActivityKind;
use App\Enums\CloseReasonKind;
use App\Enums\LeadStatusKind;
use App\Enums\TaskKind;
use App\Filament\Widgets\ActivityCountsWidget;
use App\Filament\Widgets\LeadsByStatusChart;
use App\Filament\Widgets\MyTasksTodayWidget;
use App\Filament\Widgets\PipelineByStageChart;
use App\Filament\Widgets\RevenueWonByMonthChart;
use App\Filament\Widgets\SalesKpisWidget;
use App\Filament\Widgets\StaleDealsWidget;
use App\Filament\Widgets\UpcomingFollowUpsWidget;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Pipeline;
use App\Models\Task;
use App\Models\User;
use App\Services\Deals\DealCloseService;
use App\Services\Leads\LeadStatusWorkflow;
use App\Services\Statistics\DashboardFilters;
use App\Services\Statistics\PipelineMetrics;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The dashboard widgets (plan section 3.9): each renders for a role that
 * may see it with figures from the viewer's scope (D-4), hides from a user
 * without the permission, and follows the page filters it receives.
 */
final class DashboardWidgetsTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $rep;

    private User $other;

    private Lead $leadA;

    private Deal $openDeal;

    private Deal $staleDeal;

    private Task $dueToday;

    private Task $overdue;

    private Task $followUp;

    private Task $managerMeeting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-09-06 12:00:00', 'Asia/Riyadh'));

        $team = $this->makeTeam();
        $this->admin = $this->admin();
        $this->manager = $this->salesManager($team);
        $this->rep = $this->salesRep($team);
        $this->other = $this->salesRep();

        $this->buildScenario();
    }

    // --- Sales KPIs ---

    #[Test]
    public function the_kpi_strip_renders_for_an_admin_with_every_figure_in_reach(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SalesKpisWidget::class, ['pageFilters' => $this->september()])
            ->assertSee(__('dashboard.kpis.new_leads'))
            ->assertSee(__('dashboard.kpis.conversion_rate'))
            ->assertSee(__('dashboard.kpis.open_deals'))
            ->assertSee(__('dashboard.kpis.weighted_pipeline'))
            ->assertSee(__('dashboard.kpis.won'))
            ->assertSee(__('dashboard.kpis.lost'))
            ->assertSee(__('dashboard.kpis.win_rate'))
            ->assertSee(__('dashboard.kpis.new_leads_description', ['qualified' => self::number(1), 'converted' => self::number(0)]))
            ->assertSee(__('dashboard.kpis.won_description', ['amount' => self::money(3000)]));

        $widget = $component->instance();
        assert($widget instanceof SalesKpisWidget);
        $kpis = $widget->kpis();

        $this->assertSame(4, $kpis['new_leads']);
        $this->assertSame(1, $kpis['qualified']);
        $this->assertSame(3, $kpis['open_deals_count']);
        $this->assertSame(8000.0, $kpis['open_deals_amount']);
        $this->assertSame(800.0, $kpis['weighted_pipeline']);
        $this->assertSame(1, $kpis['won_count']);
        $this->assertSame(3000.0, $kpis['won_amount']);
        $this->assertSame(100.0, $kpis['win_rate']);
    }

    #[Test]
    public function the_kpi_strip_shows_a_rep_their_own_figures_only(): void
    {
        $widget = Livewire::actingAs($this->rep)
            ->test(SalesKpisWidget::class, ['pageFilters' => $this->september()])
            ->assertSee(__('dashboard.kpis.open_deals_description', ['amount' => self::money(3000)]))
            ->instance();
        assert($widget instanceof SalesKpisWidget);
        $kpis = $widget->kpis();

        $this->assertSame(2, $kpis['new_leads']);
        $this->assertSame(2, $kpis['open_deals_count']);
        $this->assertSame(3000.0, $kpis['open_deals_amount']);
        $this->assertSame(1, $kpis['won_count']);
    }

    #[Test]
    public function the_page_filters_change_the_kpi_figures(): void
    {
        $august = Livewire::actingAs($this->rep)
            ->test(SalesKpisWidget::class, ['pageFilters' => ['from' => '2026-08-01', 'to' => '2026-08-31']])
            ->instance();
        assert($august instanceof SalesKpisWidget);

        $this->assertSame(0, $august->kpis()['new_leads']);
        $this->assertSame(0, $august->kpis()['won_count']);
        // The open pipeline is not bound to the period.
        $this->assertSame(2, $august->kpis()['open_deals_count']);

        $ownerNarrowed = Livewire::actingAs($this->manager)
            ->test(SalesKpisWidget::class, ['pageFilters' => $this->september() + ['owner_id' => $this->rep->getKey()]])
            ->instance();
        assert($ownerNarrowed instanceof SalesKpisWidget);
        $this->assertSame(2, $ownerNarrowed->kpis()['new_leads']);

        $ownerOutsideReach = Livewire::actingAs($this->manager)
            ->test(SalesKpisWidget::class, ['pageFilters' => $this->september() + ['owner_id' => $this->other->getKey()]])
            ->instance();
        assert($ownerOutsideReach instanceof SalesKpisWidget);
        $this->assertSame(3, $ownerOutsideReach->kpis()['new_leads']);
    }

    // --- Charts ---

    #[Test]
    public function the_leads_by_status_chart_paints_open_statuses_with_their_badge_colours(): void
    {
        $statuses = LeadStatus::query()->where('is_active', true)->orderBy('sort')->get()->keyBy('kind');

        $widget = Livewire::actingAs($this->rep)
            ->test(LeadsByStatusChart::class, ['pageFilters' => $this->september()])
            ->assertSee(__('dashboard.charts.leads_by_status'))
            ->instance();
        assert($widget instanceof LeadsByStatusChart);
        $data = $widget->chartData();

        $this->assertSame([
            $statuses[LeadStatusKind::New->value]->display_name,
            $statuses[LeadStatusKind::Working->value]->display_name,
            $statuses[LeadStatusKind::Qualified->value]->display_name,
        ], $data['labels']);
        $this->assertSame(__('dashboard.charts.dataset_leads'), $data['datasets'][0]['label']);
        $this->assertSame([1, 0, 1], $data['datasets'][0]['data']);
        $this->assertSame(FilamentColor::getColor($statuses[LeadStatusKind::New->value]->color->value)[500], $data['datasets'][0]['backgroundColor'][0]);
        $this->assertStringStartsNotWith('#', $data['datasets'][0]['backgroundColor'][0]);

        $admin = Livewire::actingAs($this->admin)->test(LeadsByStatusChart::class, ['pageFilters' => $this->september()])->instance();
        assert($admin instanceof LeadsByStatusChart);
        $this->assertSame([3, 0, 1], $admin->chartData()['datasets'][0]['data']);
    }

    #[Test]
    public function the_pipeline_by_stage_chart_carries_the_amount_and_the_weighted_datasets(): void
    {
        $default = app(PipelineMetrics::class)->chartedPipeline(DashboardFilters::currentMonth());
        $this->assertInstanceOf(Pipeline::class, $default);

        // With no pipeline chosen the heading names the pipeline the bars cover,
        // because the KPI strip above them spans every pipeline.
        $widget = Livewire::actingAs($this->admin)
            ->test(PipelineByStageChart::class, ['pageFilters' => $this->september()])
            ->assertSee(__('dashboard.charts.pipeline_by_stage_default', ['pipeline' => $default->display_name]))
            ->instance();
        assert($widget instanceof PipelineByStageChart);
        $data = $widget->chartData();
        $this->assertSame(
            __('dashboard.charts.pipeline_by_stage_default', ['pipeline' => $default->display_name]),
            $widget->getHeading(),
        );

        // With a pipeline chosen the plain heading is enough.
        $chosen = Livewire::actingAs($this->admin)
            ->test(PipelineByStageChart::class, ['pageFilters' => $this->september() + ['pipeline_id' => $default->getKey()]])
            ->instance();
        assert($chosen instanceof PipelineByStageChart);
        $this->assertSame(__('dashboard.charts.pipeline_by_stage'), $chosen->getHeading());

        $this->assertCount(3, $data['labels']);
        $this->assertSame($this->openDeal->stage?->display_name, $data['labels'][0]);
        $this->assertSame(__('dashboard.charts.dataset_amount'), $data['datasets'][0]['label']);
        $this->assertSame(__('dashboard.charts.dataset_weighted'), $data['datasets'][1]['label']);
        $this->assertSame([8000.0, 0.0, 0.0], $data['datasets'][0]['data']);
        $this->assertSame([800.0, 0.0, 0.0], $data['datasets'][1]['data']);

        $rep = Livewire::actingAs($this->rep)->test(PipelineByStageChart::class, ['pageFilters' => $this->september()])->instance();
        assert($rep instanceof PipelineByStageChart);
        $this->assertSame([3000.0, 0.0, 0.0], $rep->chartData()['datasets'][0]['data']);

        $newcomer = Livewire::actingAs($this->salesRep())
            ->test(PipelineByStageChart::class, ['pageFilters' => $this->september()])
            ->assertSee(__('dashboard.empty.chart'))
            ->instance();
        assert($newcomer instanceof PipelineByStageChart);
        $this->assertSame([], $newcomer->chartData());
    }

    #[Test]
    public function the_revenue_chart_labels_twelve_localised_months(): void
    {
        $widget = Livewire::actingAs($this->admin)
            ->test(RevenueWonByMonthChart::class, ['pageFilters' => $this->september()])
            ->assertSee(__('dashboard.charts.revenue_by_month'))
            ->instance();
        assert($widget instanceof RevenueWonByMonthChart);
        $data = $widget->chartData();

        $this->assertCount(12, $data['labels']);
        $this->assertSame(RevenueWonByMonthChart::monthLabel('2025-10'), $data['labels'][0]);
        $this->assertSame(RevenueWonByMonthChart::monthLabel('2026-09'), $data['labels'][11]);
        $this->assertSame(Carbon::parse('2026-09-01')->locale(app()->getLocale())->translatedFormat('M Y'), $data['labels'][11]);
        $this->assertSame(__('dashboard.charts.dataset_revenue'), $data['datasets'][0]['label']);
        $this->assertSame(3000.0, $data['datasets'][0]['data'][11]);
        $this->assertSame(3000.0, array_sum($data['datasets'][0]['data']));

        $other = Livewire::actingAs($this->other)
            ->test(RevenueWonByMonthChart::class, ['pageFilters' => $this->september()])
            ->assertSee(__('dashboard.empty.chart'))
            ->instance();
        assert($other instanceof RevenueWonByMonthChart);
        $this->assertSame([], $other->chartData());
    }

    #[Test]
    public function the_activity_chart_counts_every_kind_in_the_period(): void
    {
        $widget = Livewire::actingAs($this->rep)
            ->test(ActivityCountsWidget::class, ['pageFilters' => $this->september()])
            ->assertSee(__('dashboard.charts.activity_counts'))
            ->instance();
        assert($widget instanceof ActivityCountsWidget);
        $data = $widget->chartData();

        $this->assertSame(array_map(static fn (ActivityKind $kind): string => $kind->getLabel(), ActivityKind::cases()), $data['labels']);
        $this->assertSame(__('dashboard.charts.dataset_count'), $data['datasets'][0]['label']);
        $this->assertSame(1, $data['datasets'][0]['data'][array_search(ActivityKind::Call, ActivityKind::cases(), true)]);
        $this->assertSame(1, array_sum($data['datasets'][0]['data']));
        $this->assertSame(FilamentColor::getColor(ActivityKind::Call->getColor())[500], $data['datasets'][0]['backgroundColor'][0]);

        $other = Livewire::actingAs($this->other)
            ->test(ActivityCountsWidget::class, ['pageFilters' => $this->september()])
            ->assertSee(__('dashboard.empty.chart'))
            ->instance();
        assert($other instanceof ActivityCountsWidget);
        $this->assertSame([], $other->chartData());
    }

    // --- Tables ---

    #[Test]
    public function my_tasks_today_lists_the_viewer_overdue_and_due_tasks_with_counts(): void
    {
        Livewire::actingAs($this->rep)
            ->test(MyTasksTodayWidget::class)
            ->assertSee(__('dashboard.tables.my_tasks_today'))
            ->assertSee(__('dashboard.tables.my_tasks_today_description', ['today' => 1, 'overdue' => 1]))
            ->assertCanSeeTableRecords([$this->overdue, $this->dueToday])
            ->assertCanNotSeeTableRecords([$this->followUp, $this->managerMeeting])
            ->assertSee($this->overdue->title)
            ->assertSee($this->dueToday->title)
            ->assertDontSee($this->followUp->title)
            ->assertOk();

        Livewire::actingAs($this->manager)
            ->test(MyTasksTodayWidget::class)
            ->assertSee(__('dashboard.empty.tasks'))
            ->assertDontSee($this->overdue->title)
            ->assertOk();
    }

    #[Test]
    public function upcoming_follow_ups_span_the_viewer_reach(): void
    {
        Livewire::actingAs($this->rep)
            ->test(UpcomingFollowUpsWidget::class)
            ->assertSee(__('dashboard.tables.upcoming_follow_ups'))
            ->assertCanSeeTableRecords([$this->followUp])
            ->assertCanNotSeeTableRecords([$this->managerMeeting, $this->dueToday])
            ->assertDontSee($this->managerMeeting->title)
            ->assertOk();

        Livewire::actingAs($this->manager)
            ->test(UpcomingFollowUpsWidget::class)
            ->assertCanSeeTableRecords([$this->managerMeeting, $this->followUp])
            ->assertSee($this->followUp->title);

        Livewire::actingAs($this->other)
            ->test(UpcomingFollowUpsWidget::class)
            ->assertSee(__('dashboard.empty.follow_ups'))
            ->assertCanNotSeeTableRecords([$this->followUp]);
    }

    #[Test]
    public function stale_deals_lists_the_untouched_open_deals_in_reach(): void
    {
        Livewire::actingAs($this->admin)
            ->test(StaleDealsWidget::class, ['pageFilters' => $this->september()])
            ->assertSee(__('dashboard.tables.stale_deals'))
            ->assertCanSeeTableRecords([$this->staleDeal])
            ->assertCanNotSeeTableRecords([$this->openDeal])
            ->assertSee($this->staleDeal->title)
            ->assertDontSee($this->openDeal->title)
            ->assertOk();

        Livewire::actingAs($this->rep)
            ->test(StaleDealsWidget::class, ['pageFilters' => $this->september()])
            ->assertCanSeeTableRecords([$this->staleDeal]);

        Livewire::actingAs($this->other)
            ->test(StaleDealsWidget::class, ['pageFilters' => $this->september()])
            ->assertSee(__('dashboard.empty.stale_deals'))
            ->assertCanNotSeeTableRecords([$this->staleDeal]);
    }

    // --- Authorisation ---

    #[Test]
    public function every_widget_hides_from_a_user_without_the_permission_and_shows_to_one_who_holds_it(): void
    {
        $widgets = [
            SalesKpisWidget::class,
            LeadsByStatusChart::class,
            PipelineByStageChart::class,
            RevenueWonByMonthChart::class,
            MyTasksTodayWidget::class,
            UpcomingFollowUpsWidget::class,
            StaleDealsWidget::class,
            ActivityCountsWidget::class,
        ];

        $this->actingAs(User::factory()->create());

        foreach ($widgets as $widget) {
            $this->assertFalse($widget::canView(), $widget.' shows to a user without any permission');
        }

        foreach ([$this->admin, $this->manager, $this->rep, $this->support(), $this->readOnly()] as $user) {
            $this->actingAs($user);

            foreach ($widgets as $widget) {
                $this->assertTrue($widget::canView(), $widget.' hides from '.$user->name);
            }
        }
    }

    #[Test]
    public function a_widget_is_gated_by_its_own_entity_permission(): void
    {
        $leadsOnly = User::factory()->create();
        $leadsOnly->givePermissionTo('lead.view_any');
        $this->actingAs($leadsOnly);

        $this->assertTrue(SalesKpisWidget::canView());
        $this->assertTrue(LeadsByStatusChart::canView());
        $this->assertFalse(PipelineByStageChart::canView());
        $this->assertFalse(RevenueWonByMonthChart::canView());
        $this->assertFalse(StaleDealsWidget::canView());
        $this->assertFalse(MyTasksTodayWidget::canView());
        $this->assertFalse(UpcomingFollowUpsWidget::canView());
        $this->assertFalse(ActivityCountsWidget::canView());

        // The KPI strip then carries the lead stats and none of the deal stats.
        Livewire::actingAs($leadsOnly)
            ->test(SalesKpisWidget::class, ['pageFilters' => $this->september()])
            ->assertSee(__('dashboard.kpis.new_leads'))
            ->assertDontSee(__('dashboard.kpis.weighted_pipeline'))
            ->assertOk();
    }

    // --- Scenario ---

    /**
     * Leads: A (rep, New), B (rep, qualified), D (other), E (manager).
     * Deals: an open one for the rep (1000), a stale one for the rep
     * (2000, untouched for 20 days), an open one for the other rep (5000),
     * one won by the rep (3000). Tasks for the rep: due today, overdue, a
     * follow-up in three days; a meeting in two days for the manager. One
     * call logged by the rep on 2 September.
     */
    private function buildScenario(): void
    {
        $qualified = LeadStatus::query()->where('kind', LeadStatusKind::Qualified->value)->firstOrFail();

        $this->leadA = Lead::factory()->create(['owner_id' => $this->rep->getKey()]);
        $leadB = Lead::factory()->create(['owner_id' => $this->rep->getKey()]);
        Lead::factory()->create(['owner_id' => $this->other->getKey()]);
        Lead::factory()->create(['owner_id' => $this->manager->getKey()]);
        app(LeadStatusWorkflow::class)->transition($leadB, $qualified, $this->rep, 'Budget confirmed');

        $this->openDeal = Deal::factory()->create(['owner_id' => $this->rep->getKey(), 'title' => 'Fresh deal', 'amount' => 1000]);
        $this->staleDeal = Deal::factory()->create(['owner_id' => $this->rep->getKey(), 'title' => 'Forgotten deal', 'amount' => 2000]);
        Deal::factory()->create(['owner_id' => $this->other->getKey(), 'title' => 'Other deal', 'amount' => 5000]);
        Deal::query()->whereKey($this->staleDeal->getKey())->update(['updated_at' => now()->subDays(20)]);

        $won = DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->where('is_active', true)->orderBy('sort')->firstOrFail();
        app(DealCloseService::class)->win(
            Deal::factory()->create(['owner_id' => $this->rep->getKey(), 'title' => 'Signed deal', 'amount' => 3000]),
            $won,
            $this->rep,
        );

        $this->dueToday = Task::factory()->create(['assignee_id' => $this->rep->getKey(), 'title' => 'Send the quote', 'due_at' => now()->setTime(15, 0)]);
        $this->overdue = Task::factory()->create(['assignee_id' => $this->rep->getKey(), 'title' => 'Chase the invoice', 'due_at' => now()->subDay()]);
        $this->followUp = Task::factory()->create(['assignee_id' => $this->rep->getKey(), 'title' => 'Follow up on the demo', 'kind' => TaskKind::FollowUp, 'due_at' => now()->addDays(3)]);
        $this->managerMeeting = Task::factory()->create(['assignee_id' => $this->manager->getKey(), 'title' => 'Quarterly review meeting', 'kind' => TaskKind::Meeting, 'due_at' => now()->addDays(2)]);

        Activity::factory()->create(['owner_id' => $this->rep->getKey(), 'lead_id' => $this->leadA->getKey(), 'occurred_at' => '2026-09-02 10:00:00']);
    }

    /**
     * @return array<string, mixed>
     */
    private function september(): array
    {
        return ['from' => '2026-09-01', 'to' => '2026-09-30'];
    }

    private static function number(int $value): string
    {
        return (string) Number::format($value, locale: app()->getLocale());
    }

    private static function money(float $amount): string
    {
        return (string) Number::currency($amount, 'SAR', app()->getLocale());
    }
}
