<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ActivityKind;
use App\Enums\CloseReasonKind;
use App\Enums\LeadStatusKind;
use App\Enums\StageKind;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use App\Services\Deals\DealCloseService;
use App\Services\Leads\ConversionRequest;
use App\Services\Leads\LeadConversionWorkflow;
use App\Services\Leads\LeadStatusWorkflow;
use App\Services\Settings\PipelineService;
use App\Services\Settings\SettingsRepository;
use App\Services\Statistics\ActivityMetrics;
use App\Services\Statistics\DashboardFilters;
use App\Services\Statistics\DashboardMetrics;
use App\Services\Statistics\LeadFunnelMetrics;
use App\Services\Statistics\PipelineMetrics;
use App\Services\Statistics\RevenueMetrics;
use App\Services\Statistics\TaskMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The statistics services behind the dashboard (decisions D-4, D-7, D-8,
 * D-13): every number is computed inside the viewer's visibility scope,
 * the filters only narrow it, and the period, the buckets and the
 * rounding are exact.
 *
 * The scenario is built once per test at 2026-09-06 12:00 Asia/Riyadh
 * (the organisation timezone): a team of a manager and a rep, a rep
 * outside the team, and an admin who sees everything.
 */
final class DashboardMetricsTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $rep;

    private User $other;

    /** @var array<string, Lead> */
    private array $leads = [];

    /** @var array<string, Deal> */
    private array $deals = [];

    /** @var array<string, Task> */
    private array $tasks = [];

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
    }

    // --- Lead funnel ---

    #[Test]
    public function the_lead_funnel_follows_the_viewer_scope(): void
    {
        $this->buildLeads();
        $period = DashboardFilters::currentMonth();
        $metrics = app(LeadFunnelMetrics::class);

        // Admin sees every lead: A, B, C (rep), D (other), E (manager); F is outside the period.
        $this->assertSame(5, $metrics->newInPeriod($this->admin, $period));
        $this->assertSame(2, $metrics->qualifiedInPeriod($this->admin, $period));
        $this->assertSame(1, $metrics->convertedInPeriod($this->admin, $period));
        $this->assertSame(20.0, $metrics->conversionRate($this->admin, $period));

        // The rep sees their own three.
        $this->assertSame(3, $metrics->newInPeriod($this->rep, $period));
        $this->assertSame(2, $metrics->qualifiedInPeriod($this->rep, $period));
        $this->assertSame(1, $metrics->convertedInPeriod($this->rep, $period));
        $this->assertSame(33.33, $metrics->conversionRate($this->rep, $period));

        // The manager sees the team: the rep's three and their own one.
        $this->assertSame(4, $metrics->newInPeriod($this->manager, $period));
        $this->assertSame(25.0, $metrics->conversionRate($this->manager, $period));

        // The rep outside the team sees only D, which was never qualified.
        $this->assertSame(1, $metrics->newInPeriod($this->other, $period));
        $this->assertSame(0.0, $metrics->conversionRate($this->other, $period));
    }

    #[Test]
    public function the_counts_by_status_kind_cover_every_kind_and_the_total(): void
    {
        $this->buildLeads();
        $period = DashboardFilters::currentMonth();
        $metrics = app(LeadFunnelMetrics::class);

        $this->assertSame([
            LeadStatusKind::New->value => 3,
            LeadStatusKind::Working->value => 0,
            LeadStatusKind::Qualified->value => 1,
            LeadStatusKind::Unqualified->value => 0,
            LeadStatusKind::Converted->value => 1,
            'total' => 5,
        ], $metrics->countsByStatusKind($this->admin, $period));

        $this->assertSame([
            LeadStatusKind::New->value => 1,
            LeadStatusKind::Working->value => 0,
            LeadStatusKind::Qualified->value => 1,
            LeadStatusKind::Unqualified->value => 0,
            LeadStatusKind::Converted->value => 1,
            'total' => 3,
        ], $metrics->countsByStatusKind($this->rep, $period));
    }

    #[Test]
    public function the_open_funnel_counts_every_open_status_whatever_the_period(): void
    {
        $this->buildLeads();
        $metrics = app(LeadFunnelMetrics::class);
        $period = DashboardFilters::currentMonth();

        $statuses = LeadStatus::query()->where('is_active', true)->orderBy('sort')->get()->keyBy('kind');
        $new = $statuses[LeadStatusKind::New->value]->display_name;
        $working = $statuses[LeadStatusKind::Working->value]->display_name;
        $qualified = $statuses[LeadStatusKind::Qualified->value]->display_name;

        // F (created in August) is still open, so it counts here; the converted C does not.
        $this->assertSame([$new => 4, $working => 0, $qualified => 1], $metrics->leadsByStatus($this->admin, $period));
        $this->assertSame([$new => 2, $working => 0, $qualified => 1], $metrics->leadsByStatus($this->rep, $period));

        $series = $metrics->leadsByStatusSeries($this->rep, $period);
        $this->assertSame([$new, $working, $qualified], array_column($series, 'label'));
        $this->assertSame([2, 0, 1], array_column($series, 'count'));
        $this->assertSame($statuses[LeadStatusKind::New->value]->color, $series[0]['color']);
    }

    #[Test]
    public function the_conversion_rate_is_zero_without_leads_and_rounded_to_two_decimals(): void
    {
        $this->buildLeads();
        $metrics = app(LeadFunnelMetrics::class);

        $this->assertSame(0.0, $metrics->conversionRate($this->manager, $this->period('2026-07-01', '2026-07-31')));

        // Two more open leads for the rep: 1 converted of 6 created = 16.67 %.
        Lead::factory()->count(3)->create(['owner_id' => $this->rep->getKey()]);
        $this->assertSame(16.67, $metrics->conversionRate($this->rep, DashboardFilters::currentMonth()));
    }

    // --- Pipeline ---

    #[Test]
    public function the_open_pipeline_follows_the_viewer_scope_and_uses_the_probability_override(): void
    {
        $this->buildDeals();
        $period = DashboardFilters::currentMonth();
        $metrics = app(PipelineMetrics::class);

        // Admin: X1 (1000 × 10 %), X2 (2000 × 50 % override, in Proposal at 30 %), Y1 (5000 × 10 %).
        $this->assertSame(3, $metrics->openDealsCount($this->admin, $period));
        $this->assertSame(8000.0, $metrics->openDealsAmount($this->admin, $period));
        $this->assertSame(1600.0, $metrics->weightedPipeline($this->admin, $period));

        // Rep and manager: X1 and X2 only.
        foreach ([$this->rep, $this->manager] as $viewer) {
            $this->assertSame(2, $metrics->openDealsCount($viewer, $period));
            $this->assertSame(3000.0, $metrics->openDealsAmount($viewer, $period));
            $this->assertSame(1100.0, $metrics->weightedPipeline($viewer, $period));
        }

        $this->assertSame(1, $metrics->openDealsCount($this->other, $period));
        $this->assertSame(500.0, $metrics->weightedPipeline($this->other, $period));
    }

    #[Test]
    public function the_stage_breakdown_lists_every_open_stage_of_the_pipeline_in_order(): void
    {
        $this->buildDeals();
        $period = DashboardFilters::currentMonth();

        $stages = app(PipelineMetrics::class)->byStage($this->admin, $period);

        $expectedLabels = PipelineStage::query()
            ->where('pipeline_id', $this->deals['X1']->pipeline_id)
            ->where('kind', StageKind::Open->value)
            ->orderBy('sort')
            ->get()
            ->map(static fn (PipelineStage $stage): string => $stage->display_name)
            ->all();

        $this->assertSame($expectedLabels, array_column($stages, 'label'));
        $this->assertSame(['التأهيل', 'العرض', 'التفاوض'], array_column($stages, 'label'));
        $this->assertSame([2, 1, 0], array_column($stages, 'count'));

        app()->setLocale('en');
        $this->assertSame(['Qualification', 'Proposal', 'Negotiation'], array_column(app(PipelineMetrics::class)->byStage($this->admin, $period), 'label'));
        app()->setLocale('ar');
        $stages = app(PipelineMetrics::class)->byStage($this->admin, $period);
        $this->assertSame([2, 1, 0], array_column($stages, 'count'));
        $this->assertSame([6000.0, 2000.0, 0.0], array_column($stages, 'amount'));
        $this->assertSame([600.0, 1000.0, 0.0], array_column($stages, 'weighted'));

        $stages = app(PipelineMetrics::class)->byStage($this->rep, $period);
        $this->assertSame([1, 1, 0], array_column($stages, 'count'));
        $this->assertSame([100.0, 1000.0, 0.0], array_column($stages, 'weighted'));
    }

    #[Test]
    public function a_pipeline_filter_narrows_the_open_deals_to_that_pipeline(): void
    {
        $this->buildDeals();
        $partnerships = app(PipelineService::class)->create(['name_ar' => 'الشراكات', 'name_en' => 'Partnerships', 'is_active' => true, 'is_default' => false]);
        $stage = $partnerships->stages()->where('is_default', true)->firstOrFail();
        Deal::factory()->create(['owner_id' => $this->rep->getKey(), 'pipeline_id' => $partnerships->getKey(), 'stage_id' => $stage->getKey(), 'amount' => 900]);

        $metrics = app(PipelineMetrics::class);

        $this->assertSame(4, $metrics->openDealsCount($this->admin, DashboardFilters::currentMonth()));
        $this->assertSame(1, $metrics->openDealsCount($this->admin, $this->period('2026-09-01', '2026-09-30', pipelineId: (int) $partnerships->getKey())));
        $this->assertSame([900.0], array_column($metrics->byStage($this->admin, $this->period('2026-09-01', '2026-09-30', pipelineId: (int) $partnerships->getKey())), 'amount'));
    }

    #[Test]
    public function stale_deals_are_the_untouched_open_deals_oldest_first(): void
    {
        $this->buildDeals();
        $metrics = app(PipelineMetrics::class);
        $period = DashboardFilters::currentMonth();

        $this->assertSame(
            [$this->deals['X2']->getKey(), $this->deals['X1']->getKey()],
            $metrics->staleDeals($this->admin, $period)->modelKeys(),
        );
        $this->assertSame([$this->deals['X2']->getKey(), $this->deals['X1']->getKey()], $metrics->staleDeals($this->rep, $period)->modelKeys());
        $this->assertSame([], $metrics->staleDeals($this->other, $period)->modelKeys());

        // A shorter threshold pulls in the deal touched five days ago; a limit caps the list.
        $this->assertSame(3, $metrics->staleDeals($this->admin, $period, days: 3)->count());
        $this->assertSame([$this->deals['X2']->getKey()], $metrics->staleDeals($this->admin, $period, limit: 1)->modelKeys());
    }

    // --- Revenue ---

    #[Test]
    public function won_and_lost_in_the_period_follow_the_viewer_scope(): void
    {
        $this->buildDeals();
        $period = DashboardFilters::currentMonth();
        $metrics = app(RevenueMetrics::class);

        $this->assertSame(['count' => 2, 'amount' => 7000.0], $metrics->wonInPeriod($this->admin, $period));
        $this->assertSame(['count' => 1, 'amount' => 500.0], $metrics->lostInPeriod($this->admin, $period));
        $this->assertSame(66.67, $metrics->winRate($this->admin, $period));

        $this->assertSame(['count' => 1, 'amount' => 3000.0], $metrics->wonInPeriod($this->rep, $period));
        $this->assertSame(['count' => 1, 'amount' => 500.0], $metrics->lostInPeriod($this->rep, $period));
        $this->assertSame(50.0, $metrics->winRate($this->rep, $period));
        $this->assertSame(50.0, $metrics->winRate($this->manager, $period));

        // W3 was won in August: only the August period counts it.
        $this->assertSame(['count' => 1, 'amount' => 7000.0], $metrics->wonInPeriod($this->rep, $this->period('2026-08-01', '2026-08-31')));
        $this->assertSame(0.0, $metrics->winRate($this->rep, $this->period('2026-07-01', '2026-07-31')));
    }

    #[Test]
    public function revenue_by_month_has_twelve_buckets_with_zeros(): void
    {
        $this->buildDeals();
        $metrics = app(RevenueMetrics::class);

        $series = $metrics->revenueWonByMonth($this->admin, DashboardFilters::currentMonth());

        $this->assertCount(12, $series);
        $this->assertSame('2025-10', $series[0]['month']);
        $this->assertSame('2026-09', $series[11]['month']);
        $this->assertSame(7000.0, $series[10]['amount']);
        $this->assertSame(7000.0, $series[11]['amount']);
        $this->assertSame(14000.0, array_sum(array_column($series, 'amount')));

        $series = $metrics->revenueWonByMonth($this->rep, DashboardFilters::currentMonth());
        $this->assertSame(7000.0, $series[10]['amount']);
        $this->assertSame(3000.0, $series[11]['amount']);

        $this->assertCount(3, $metrics->revenueWonByMonth($this->admin, DashboardFilters::currentMonth(), months: 3));
    }

    #[Test]
    public function the_monthly_and_daily_buckets_follow_the_organisation_timezone_not_the_application_one(): void
    {
        $this->buildLeads();
        // Tokyo is six hours ahead of the application timezone (Asia/Riyadh): 01:00 on the 1st there is 19:00 on the 31st here.
        app(SettingsRepository::class)->update([SettingsRepository::TIMEZONE => 'Asia/Tokyo'], $this->admin);
        $won = DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->where('is_active', true)->orderBy('sort')->firstOrFail();
        $deal = app(DealCloseService::class)->win(Deal::factory()->create(['owner_id' => $this->rep->getKey(), 'title' => 'Deal boundary', 'amount' => 900]), $won, $this->rep);
        Deal::withoutWorkflowGuard(function () use ($deal): void {
            // Eloquent stores the Carbon's own wall clock, so the instant is pinned to the application timezone here.
            $deal->won_at = Carbon::parse('2026-09-01 01:00:00', 'Asia/Tokyo')->setTimezone((string) config('app.timezone'));
            $deal->save();
        });
        Activity::factory()->create(['owner_id' => $this->rep->getKey(), 'lead_id' => $this->leads['A']->getKey(), 'occurred_at' => Carbon::parse('2026-09-01 00:30:00', 'Asia/Tokyo')->setTimezone((string) config('app.timezone'))]);

        $this->assertSame('2026-08-31 19:00:00', $deal->refresh()->won_at?->format('Y-m-d H:i:s'));

        $series = app(RevenueMetrics::class)->revenueWonByMonth($this->admin, DashboardFilters::currentMonth());
        $byMonth = array_column($series, 'amount', 'month');
        $this->assertSame(900.0, $byMonth['2026-09']);
        $this->assertSame(0.0, $byMonth['2026-08']);

        // The KPI strip agrees: September in Tokyo holds the win, August does not.
        $this->assertSame(1, app(RevenueMetrics::class)->wonInPeriod($this->admin, $this->period('2026-09-01', '2026-09-30'))['count']);
        $this->assertSame(0, app(RevenueMetrics::class)->wonInPeriod($this->admin, $this->period('2026-08-01', '2026-08-31'))['count']);

        $days = app(ActivityMetrics::class)->perDay($this->admin, DashboardFilters::currentMonth());
        $byDay = array_column($days, 'count', 'day');
        $this->assertSame(1, $byDay['2026-09-01']);
        $this->assertSame(0, $byDay['2026-08-31']);
    }

    // --- Filters ---

    #[Test]
    public function the_owner_filter_narrows_and_an_owner_outside_reach_is_ignored(): void
    {
        $this->buildLeads();
        $this->buildDeals();
        $leads = app(LeadFunnelMetrics::class);
        $revenue = app(RevenueMetrics::class);

        $repOnly = DashboardFilters::fromArray(['owner_id' => $this->rep->getKey()], $this->manager);
        $this->assertSame($this->rep->getKey(), $repOnly->ownerId);
        $this->assertSame(3, $leads->newInPeriod($this->manager, $repOnly));
        $this->assertSame(['count' => 1, 'amount' => 3000.0], $revenue->wonInPeriod($this->manager, $repOnly));

        $outsideReach = DashboardFilters::fromArray(['owner_id' => $this->other->getKey()], $this->manager);
        $this->assertNull($outsideReach->ownerId);
        $this->assertSame(4, $leads->newInPeriod($this->manager, $outsideReach));

        $adminSeesOther = DashboardFilters::fromArray(['owner_id' => $this->other->getKey()], $this->admin);
        $this->assertSame($this->other->getKey(), $adminSeesOther->ownerId);
        $this->assertSame(1, $leads->newInPeriod($this->admin, $adminSeesOther));

        // A rep may only pick themselves.
        $this->assertNull(DashboardFilters::fromArray(['owner_id' => $this->manager->getKey()], $this->rep)->ownerId);
        $this->assertSame($this->rep->getKey(), DashboardFilters::fromArray(['owner_id' => $this->rep->getKey()], $this->rep)->ownerId);
    }

    #[Test]
    public function the_team_filter_is_honoured_only_for_a_viewer_who_sees_everything(): void
    {
        $this->buildLeads();
        $team = $this->manager->team;
        $this->assertNotNull($team);
        $leads = app(LeadFunnelMetrics::class);

        $adminTeam = DashboardFilters::fromArray(['team_id' => $team->getKey()], $this->admin);
        $this->assertSame($team->getKey(), $adminTeam->teamId);
        $this->assertSame(4, $leads->newInPeriod($this->admin, $adminTeam));

        $this->assertNull(DashboardFilters::fromArray(['team_id' => $team->getKey()], $this->manager)->teamId);
        $this->assertNull(DashboardFilters::fromArray(['team_id' => 999999], $this->admin)->teamId);
    }

    #[Test]
    public function an_unusable_period_falls_back_to_the_current_month(): void
    {
        $expectedFrom = '2026-09-01 00:00:00';
        $expectedTo = '2026-09-30 23:59:59';

        foreach ([
            [],
            ['from' => '2026-09-10', 'to' => '2026-09-01'],
            ['from' => 'not a date', 'to' => '2026-09-01'],
            ['from' => '2025-01-01', 'to' => '2026-09-01'],
        ] as $filters) {
            $period = DashboardFilters::fromArray($filters, $this->admin);

            $this->assertSame($expectedFrom, $period->from->format('Y-m-d H:i:s'));
            $this->assertSame($expectedTo, $period->to->format('Y-m-d H:i:s'));
        }

        $year = DashboardFilters::fromArray(['from' => '2025-09-06', 'to' => '2026-09-06'], $this->admin);
        $this->assertSame(366, $year->days());

        $custom = DashboardFilters::fromArray(['from' => '2026-08-01', 'to' => '2026-08-15', 'pipeline_id' => 999999], $this->admin);
        $this->assertSame('2026-08-01', $custom->from->toDateString());
        $this->assertSame('2026-08-15 23:59:59', $custom->to->format('Y-m-d H:i:s'));
        $this->assertSame(15, $custom->days());
        $this->assertNull($custom->pipelineId);
        $this->assertSame('Asia/Riyadh', $custom->from->getTimezone()->getName());
    }

    #[Test]
    public function the_kpi_summary_gathers_every_figure_inside_the_scope(): void
    {
        $this->buildLeads();
        $this->buildDeals();

        $kpis = app(DashboardMetrics::class)->salesKpis($this->rep, DashboardFilters::currentMonth());

        $this->assertSame(3, $kpis['new_leads']);
        $this->assertSame(2, $kpis['qualified']);
        $this->assertSame(1, $kpis['converted']);
        $this->assertSame(33.33, $kpis['conversion_rate']);
        $this->assertSame(2, $kpis['open_deals_count']);
        $this->assertSame(3000.0, $kpis['open_deals_amount']);
        $this->assertSame(1100.0, $kpis['weighted_pipeline']);
        $this->assertSame(1, $kpis['won_count']);
        $this->assertSame(3000.0, $kpis['won_amount']);
        $this->assertSame(1, $kpis['lost_count']);
        $this->assertSame(500.0, $kpis['lost_amount']);
        $this->assertSame(50.0, $kpis['win_rate']);
        $this->assertCount(12, $kpis['revenue_by_month']);
        $this->assertSame(3000.0, $kpis['revenue_by_month'][11]);
    }

    // --- Tasks ---

    #[Test]
    public function my_tasks_today_and_overdue_are_the_viewer_own_open_tasks_in_the_organisation_day(): void
    {
        $this->buildTasks();
        $metrics = app(TaskMetrics::class);

        // T2 (09:00) is already behind the clock but still due today: the calendar day decides, not the moment.
        $this->assertSame([$this->tasks['T2']->getKey(), $this->tasks['T1']->getKey()], $metrics->myTasksToday($this->rep)->modelKeys());
        $this->assertSame([$this->tasks['T3']->getKey()], $metrics->myOverdue($this->rep)->modelKeys());
        $this->assertTrue($metrics->isOverdue($this->tasks['T3']));
        $this->assertFalse($metrics->isOverdue($this->tasks['T2']));
        $this->assertFalse($metrics->isOverdue($this->tasks['DONE']));

        // Due today is the same organisation day the widgets print, so the colour and the date agree.
        $this->assertTrue($metrics->isDueToday($this->tasks['T2']));
        $this->assertTrue($metrics->isDueToday($this->tasks['T1']));
        $this->assertFalse($metrics->isDueToday($this->tasks['T3']));
        $this->assertFalse($metrics->isDueToday($this->tasks['T4']));
        $this->assertFalse($metrics->isDueToday($this->tasks['DONE']));
        $this->assertSame(
            [$this->tasks['T3']->getKey(), $this->tasks['T2']->getKey(), $this->tasks['T1']->getKey()],
            $metrics->myAttentionQuery($this->rep)->pluck('tasks.id')->all(),
        );

        // The manager's own list holds only the manager's task, even though the rep's are in reach.
        $this->assertSame([], $metrics->myTasksToday($this->manager)->modelKeys());
        $this->assertSame([], $metrics->myOverdue($this->manager)->modelKeys());
    }

    #[Test]
    public function upcoming_follow_ups_span_the_viewer_reach(): void
    {
        $this->buildTasks();
        $metrics = app(TaskMetrics::class);

        $this->assertSame([$this->tasks['FU1']->getKey()], $metrics->upcomingFollowUps($this->rep)->modelKeys());
        $this->assertSame([$this->tasks['M1']->getKey(), $this->tasks['FU1']->getKey()], $metrics->upcomingFollowUps($this->manager)->modelKeys());
        $this->assertSame(
            [$this->tasks['M1']->getKey(), $this->tasks['FU1']->getKey(), $this->tasks['C1']->getKey()],
            $metrics->upcomingFollowUps($this->admin)->modelKeys(),
        );

        // A wider window reaches FU2 (in ten days); the plain task T4 never appears.
        $this->assertSame(
            [$this->tasks['FU1']->getKey(), $this->tasks['FU2']->getKey()],
            $metrics->upcomingFollowUps($this->rep, days: 14)->modelKeys(),
        );

        // The window opens at the start of the organisation's day: a follow-up
        // due at 09:00, already behind the 12:00 clock, is still listed — no
        // other dashboard list would catch it for the manager.
        $this->tasks['FU0'] = Task::factory()->create([
            'assignee_id' => $this->rep->getKey(),
            'title' => 'Follow-up FU0',
            'kind' => TaskKind::FollowUp,
            'due_at' => now()->setTime(9, 0),
        ]);

        $this->assertSame(
            [$this->tasks['FU0']->getKey(), $this->tasks['FU1']->getKey()],
            $metrics->upcomingFollowUps($this->rep)->modelKeys(),
        );
        $this->assertContains($this->tasks['FU0']->getKey(), $metrics->upcomingFollowUps($this->manager)->modelKeys());
    }

    #[Test]
    public function task_counts_by_status_cover_the_period_inside_the_scope(): void
    {
        $this->buildTasks();
        $metrics = app(TaskMetrics::class);
        $period = DashboardFilters::currentMonth();

        $this->assertSame([
            TaskStatus::Pending->value => 8,
            TaskStatus::InProgress->value => 0,
            TaskStatus::Completed->value => 1,
            TaskStatus::Cancelled->value => 0,
        ], $metrics->countsByStatus($this->admin, $period));

        $this->assertSame([
            TaskStatus::Pending->value => 6,
            TaskStatus::InProgress->value => 0,
            TaskStatus::Completed->value => 1,
            TaskStatus::Cancelled->value => 0,
        ], $metrics->countsByStatus($this->rep, $period));

        $this->assertSame(0, array_sum($metrics->countsByStatus($this->rep, $this->period('2026-07-01', '2026-07-31'))));
    }

    // --- Activities ---

    #[Test]
    public function activity_counts_by_kind_follow_the_period_and_the_scope(): void
    {
        $this->buildLeads();
        $this->buildActivities();
        $metrics = app(ActivityMetrics::class);
        $period = DashboardFilters::currentMonth();

        $admin = $metrics->countsByKind($this->admin, $period);
        $this->assertSame(2, $admin[ActivityKind::Call->value]);
        $this->assertSame(1, $admin[ActivityKind::Email->value]);
        $this->assertSame(1, $admin[ActivityKind::Meeting->value]);
        $this->assertSame(0, $admin[ActivityKind::Note->value]);
        $this->assertCount(count(ActivityKind::cases()), $admin);

        $rep = $metrics->countsByKind($this->rep, $period);
        $this->assertSame(2, $rep[ActivityKind::Call->value]);
        $this->assertSame(1, $rep[ActivityKind::Email->value]);
        $this->assertSame(0, $rep[ActivityKind::Meeting->value]);
        $this->assertSame($rep, $metrics->countsByKind($this->manager, $period));

        $august = $metrics->countsByKind($this->rep, $this->period('2026-08-01', '2026-08-31'));
        $this->assertSame(1, $august[ActivityKind::Call->value]);
        $this->assertSame(1, array_sum($august));
    }

    #[Test]
    public function activities_per_day_cover_the_trailing_window_with_zeros(): void
    {
        $this->buildLeads();
        $this->buildActivities();

        $series = app(ActivityMetrics::class)->perDay($this->admin, DashboardFilters::currentMonth());

        $this->assertCount(14, $series);
        $this->assertSame('2026-08-24', $series[0]['day']);
        $this->assertSame('2026-09-06', $series[13]['day']);
        $this->assertSame(4, array_sum(array_column($series, 'count')));
        $this->assertSame(1, $series[9]['count']);   // 2026-09-02
        $this->assertSame(0, $series[13]['count']);

        $rep = app(ActivityMetrics::class)->perDay($this->rep, DashboardFilters::currentMonth());
        $this->assertSame(3, array_sum(array_column($rep, 'count')));
    }

    // --- Scenario ---

    /**
     * A, B, C for the rep (B qualified, C qualified then converted), D for
     * the other rep, E for the manager, all created now; F for the rep,
     * created in August.
     */
    private function buildLeads(): void
    {
        $qualified = LeadStatus::query()->where('kind', LeadStatusKind::Qualified->value)->firstOrFail();

        $this->leads['A'] = Lead::factory()->create(['owner_id' => $this->rep->getKey(), 'first_name' => 'Lead', 'last_name' => 'Alpha']);
        $this->leads['B'] = Lead::factory()->create(['owner_id' => $this->rep->getKey(), 'first_name' => 'Lead', 'last_name' => 'Beta']);
        $this->leads['C'] = Lead::factory()->create(['owner_id' => $this->rep->getKey(), 'first_name' => 'Lead', 'last_name' => 'Gamma']);
        $this->leads['D'] = Lead::factory()->create(['owner_id' => $this->other->getKey(), 'first_name' => 'Lead', 'last_name' => 'Delta']);
        $this->leads['E'] = Lead::factory()->create(['owner_id' => $this->manager->getKey(), 'first_name' => 'Lead', 'last_name' => 'Epsilon']);
        $this->leads['F'] = Lead::factory()->create(['owner_id' => $this->rep->getKey(), 'first_name' => 'Lead', 'last_name' => 'Foxtrot']);

        Lead::query()->whereKey($this->leads['F']->getKey())->update(['created_at' => '2026-08-15 10:00:00']);

        $workflow = app(LeadStatusWorkflow::class);
        $workflow->transition($this->leads['B'], $qualified, $this->rep, 'Budget confirmed');
        $workflow->transition($this->leads['C'], $qualified, $this->rep, 'Decision maker reached');

        app(LeadConversionWorkflow::class)->convert(
            $this->leads['C'],
            new ConversionRequest(accountMode: ConversionRequest::ACCOUNT_NONE),
            $this->rep,
        );
    }

    /**
     * Open: X1 (rep, 1000, Qualification, untouched for 20 days), X2 (rep,
     * 2000, Proposal, 50 % override, last activity 30 days ago), Y1 (other,
     * 5000, touched 5 days ago). Closed through DealCloseService: W1 (rep,
     * 3000) and W2 (other, 4000) won now, W3 (rep, 7000) won in August,
     * L1 (rep, 500) lost now.
     */
    private function buildDeals(): void
    {
        $won = DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->where('is_active', true)->orderBy('sort')->firstOrFail();
        $lost = DealCloseReason::query()->where('kind', CloseReasonKind::Lost->value)->where('is_active', true)->orderBy('sort')->firstOrFail();
        $close = app(DealCloseService::class);

        $this->deals['X1'] = Deal::factory()->create(['owner_id' => $this->rep->getKey(), 'title' => 'Deal X1', 'amount' => 1000]);
        $proposal = PipelineStage::query()
            ->where('pipeline_id', $this->deals['X1']->pipeline_id)
            ->where('kind', StageKind::Open->value)
            ->orderBy('sort')
            ->skip(1)
            ->firstOrFail();
        $this->deals['X2'] = Deal::factory()->create(['owner_id' => $this->rep->getKey(), 'title' => 'Deal X2', 'amount' => 2000, 'probability' => 50, 'stage_id' => $proposal->getKey()]);
        $this->deals['Y1'] = Deal::factory()->create(['owner_id' => $this->other->getKey(), 'title' => 'Deal Y1', 'amount' => 5000]);

        Deal::query()->whereKey($this->deals['X1']->getKey())->update(['updated_at' => now()->subDays(20), 'last_activity_at' => null]);
        Deal::query()->whereKey($this->deals['X2']->getKey())->update(['last_activity_at' => now()->subDays(30)]);
        Deal::query()->whereKey($this->deals['Y1']->getKey())->update(['last_activity_at' => now()->subDays(5)]);

        $this->deals['W1'] = $close->win(Deal::factory()->create(['owner_id' => $this->rep->getKey(), 'title' => 'Deal W1', 'amount' => 3000]), $won, $this->rep);
        $this->deals['W2'] = $close->win(Deal::factory()->create(['owner_id' => $this->other->getKey(), 'title' => 'Deal W2', 'amount' => 4000]), $won, $this->other);
        $this->deals['W3'] = $close->win(Deal::factory()->create(['owner_id' => $this->rep->getKey(), 'title' => 'Deal W3', 'amount' => 7000]), $won, $this->rep);
        $this->deals['L1'] = $close->lose(Deal::factory()->create(['owner_id' => $this->rep->getKey(), 'title' => 'Deal L1', 'amount' => 500]), $lost, $this->rep, 'No budget');

        Deal::withoutWorkflowGuard(function (): void {
            $this->deals['W3']->won_at = Carbon::parse('2026-08-10 09:00:00');
            $this->deals['W3']->save();
        });
    }

    /**
     * For the rep: T1 due today 15:00, T2 due today 09:00 (behind the
     * clock, still today), T3 due yesterday (overdue), T4 due tomorrow, FU1 follow-up in 3 days, FU2
     * follow-up in 10 days, a completed task due today. C1 call in 5 days
     * for the other rep, M1 meeting in 2 days for the manager.
     */
    private function buildTasks(): void
    {
        $rep = $this->rep->getKey();

        $this->tasks['T1'] = Task::factory()->create(['assignee_id' => $rep, 'title' => 'Task T1', 'due_at' => now()->setTime(15, 0)]);
        $this->tasks['T2'] = Task::factory()->create(['assignee_id' => $rep, 'title' => 'Task T2', 'due_at' => now()->setTime(9, 0)]);
        $this->tasks['T3'] = Task::factory()->create(['assignee_id' => $rep, 'title' => 'Task T3', 'due_at' => now()->subDay()]);
        $this->tasks['T4'] = Task::factory()->create(['assignee_id' => $rep, 'title' => 'Task T4', 'due_at' => now()->addDay()]);
        $this->tasks['FU1'] = Task::factory()->create(['assignee_id' => $rep, 'title' => 'Follow-up FU1', 'kind' => TaskKind::FollowUp, 'due_at' => now()->addDays(3)]);
        $this->tasks['FU2'] = Task::factory()->create(['assignee_id' => $rep, 'title' => 'Follow-up FU2', 'kind' => TaskKind::FollowUp, 'due_at' => now()->addDays(10)]);
        $this->tasks['DONE'] = Task::factory()->completed()->create(['assignee_id' => $rep, 'title' => 'Task done', 'due_at' => now()->setTime(16, 0)]);
        $this->tasks['C1'] = Task::factory()->create(['assignee_id' => $this->other->getKey(), 'title' => 'Call C1', 'kind' => TaskKind::Call, 'due_at' => now()->addDays(5)]);
        $this->tasks['M1'] = Task::factory()->create(['assignee_id' => $this->manager->getKey(), 'title' => 'Meeting M1', 'kind' => TaskKind::Meeting, 'due_at' => now()->addDays(2)]);
    }

    /**
     * For the rep: calls on 2 and 3 September, an email on 4 September, a
     * call on 20 August. For the other rep: a meeting on 5 September. All
     * against lead A so no extra lead is created.
     */
    private function buildActivities(): void
    {
        $lead = $this->leads['A']->getKey();
        $rep = $this->rep->getKey();

        Activity::factory()->create(['owner_id' => $rep, 'lead_id' => $lead, 'occurred_at' => '2026-09-02 10:00:00']);
        Activity::factory()->create(['owner_id' => $rep, 'lead_id' => $lead, 'occurred_at' => '2026-09-03 10:00:00']);
        Activity::factory()->ofKind(ActivityKind::Email)->create(['owner_id' => $rep, 'lead_id' => $lead, 'occurred_at' => '2026-09-04 10:00:00']);
        Activity::factory()->create(['owner_id' => $rep, 'lead_id' => $lead, 'occurred_at' => '2026-08-20 10:00:00']);
        Activity::factory()->ofKind(ActivityKind::Meeting)->create(['owner_id' => $this->other->getKey(), 'lead_id' => $lead, 'occurred_at' => '2026-09-05 10:00:00']);
    }

    private function period(string $from, string $to, ?int $pipelineId = null): DashboardFilters
    {
        return DashboardFilters::fromArray(['from' => $from, 'to' => $to, 'pipeline_id' => $pipelineId], $this->admin);
    }
}
