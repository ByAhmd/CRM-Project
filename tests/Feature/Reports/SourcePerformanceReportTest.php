<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\CloseReasonKind;
use App\Enums\LeadStatusKind;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Deals\DealCloseService;
use App\Services\Leads\ConversionRequest;
use App\Services\Leads\LeadConversionWorkflow;
use App\Services\Leads\LeadStatusWorkflow;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use App\Services\Statistics\Reports\SourcePerformanceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Source performance (module 23): leads, qualification, conversion and
 * won deals per lead source, inside the viewer's scope (D-4, D-13).
 *
 * Fixture (September 2026, team Riyadh = manager + rep A; rep B has no team):
 * - rep A: two Website leads — one stays New, one is qualified, converted
 *   with a deal (5,000) that is then won; one Referral lead whose deal
 *   (1,500, created without a source but linked to the lead) is won;
 * - rep B: one lead without a source; a Website deal (2,000) won in
 *   August (outside the period).
 */
final class SourcePerformanceReportTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    private User $manager;

    private User $repA;

    private User $repB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));

        $this->team = $this->makeTeam();
        $this->admin = $this->admin();
        $this->manager = $this->salesManager($this->team);
        $this->repA = $this->salesRep($this->team);
        $this->repB = $this->salesRep();

        $website = LeadSource::query()->where('name_en', 'Website')->firstOrFail();
        $referral = LeadSource::query()->where('name_en', 'Referral')->firstOrFail();
        $qualified = LeadStatus::query()->where('kind', LeadStatusKind::Qualified->value)->firstOrFail();
        $wonReason = DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->firstOrFail();

        Lead::factory()->create(['owner_id' => $this->repA->getKey(), 'lead_source_id' => $website->getKey()]);

        $converted = Lead::factory()->create(['owner_id' => $this->repA->getKey(), 'lead_source_id' => $website->getKey(), 'company_name' => 'Horizon Trading']);
        app(LeadStatusWorkflow::class)->transition($converted, $qualified, $this->repA, 'Budget confirmed');
        $result = app(LeadConversionWorkflow::class)->convert($converted, new ConversionRequest(createDeal: true, dealAmount: '5000'), $this->repA);
        $deal = $result->deal;
        $this->assertNotNull($deal);
        app(DealCloseService::class)->win($deal, $wonReason, $this->repA);

        $referred = Lead::factory()->create(['owner_id' => $this->repA->getKey(), 'lead_source_id' => $referral->getKey()]);
        $linked = Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 1500, 'lead_source_id' => null, 'lead_id' => $referred->getKey()]);
        app(DealCloseService::class)->win($linked, $wonReason, $this->repA);

        Lead::factory()->create(['owner_id' => $this->repB->getKey(), 'lead_source_id' => null]);

        $this->travelTo(Carbon::parse('2026-08-20 10:00:00'));
        $august = Deal::factory()->create(['owner_id' => $this->repB->getKey(), 'amount' => 2000, 'lead_source_id' => $website->getKey()]);
        app(DealCloseService::class)->win($august, $wonReason, $this->repB);
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
    }

    #[Test]
    public function an_admin_sees_every_active_source_in_order_with_leads_conversions_and_won_deals(): void
    {
        $rows = $this->report()->rows($this->admin, $this->filters());
        $bySource = $rows->keyBy(fn (ReportRow $row): string => $row->label);

        $this->assertSame(LeadSource::query()->count() + 1, $rows->count());
        $this->assertSame(['leads' => 2, 'qualified' => 1, 'converted' => 1, 'deals_won' => 1, 'won_amount' => 5000.0, 'conversion_rate' => 50.0], $bySource->get($this->label('Website'))?->values);
        $this->assertSame(['leads' => 1, 'qualified' => 0, 'converted' => 0, 'deals_won' => 1, 'won_amount' => 1500.0, 'conversion_rate' => 0.0], $bySource->get($this->label('Referral'))?->values);
        $this->assertSame(['leads' => 1, 'qualified' => 0, 'converted' => 0, 'deals_won' => 0, 'won_amount' => 0.0, 'conversion_rate' => 0.0], $bySource->get(__('reports.labels.no_source'))?->values);
        $this->assertSame(['leads' => 0, 'qualified' => 0, 'converted' => 0, 'deals_won' => 0, 'won_amount' => 0.0, 'conversion_rate' => 0.0], $bySource->get($this->label('Event'))?->values);
        $this->assertSame(__('reports.labels.no_source'), $rows->last()?->label);
        $this->assertSame(['leads' => 4, 'qualified' => 1, 'converted' => 1, 'deals_won' => 2, 'won_amount' => 6500.0, 'conversion_rate' => 25.0], $this->report()->totals($rows)->values);
    }

    #[Test]
    public function a_manager_sees_the_team_and_reps_see_only_their_own_records(): void
    {
        $manager = $this->report()->totals($this->report()->rows($this->manager, $this->filters()));
        $repA = $this->report()->totals($this->report()->rows($this->repA, $this->filters()));
        $repB = $this->report()->rows($this->repB, $this->filters());

        $this->assertSame(['leads' => 3, 'qualified' => 1, 'converted' => 1, 'deals_won' => 2, 'won_amount' => 6500.0, 'conversion_rate' => 33.3], $manager->values);
        $this->assertSame($manager->values, $repA->values);
        $this->assertSame(['leads' => 1, 'qualified' => 0, 'converted' => 0, 'deals_won' => 0, 'won_amount' => 0.0, 'conversion_rate' => 0.0], $this->report()->totals($repB)->values);
        $this->assertSame(__('reports.labels.no_source'), $repB->last()?->label);
    }

    #[Test]
    public function the_period_bounds_the_lead_creation_and_the_win_date(): void
    {
        $august = $this->report()->rows($this->admin, $this->filters(['from' => '2026-08-01', 'to' => '2026-08-31']));
        $website = $august->first(fn (ReportRow $row): bool => $row->label === $this->label('Website'));

        $this->assertSame(['leads' => 0, 'qualified' => 0, 'converted' => 0, 'deals_won' => 1, 'won_amount' => 2000.0, 'conversion_rate' => 0.0], $website?->values);
        $this->assertSame(LeadSource::query()->count(), $august->count(), 'no lead without a source in August, so no "no source" line');
    }

    #[Test]
    public function the_owner_filter_narrows_and_never_widens(): void
    {
        $adminOnRepB = $this->report()->totals($this->report()->rows($this->admin, $this->filters(['owner_id' => $this->repB->getKey()])));

        $this->assertSame(1, (int) $adminOnRepB->value('leads'));
        $this->assertSame(0, (int) $adminOnRepB->value('deals_won'));

        $managerOnRepB = $this->filters(['owner_id' => $this->repB->getKey()], $this->manager);

        $this->assertNull($managerOnRepB->ownerId);
        $this->assertSame(3, (int) $this->report()->totals($this->report()->rows($this->manager, $managerOnRepB))->value('leads'));
    }

    #[Test]
    public function the_chart_plots_leads_conversions_and_won_deals_per_source(): void
    {
        $chart = $this->report()->chart($this->admin, $this->filters());

        $this->assertCount(LeadSource::query()->count() + 1, $chart['labels']);
        $this->assertSame([__('reports.chart.leads'), __('reports.chart.converted'), __('reports.chart.deals_won')], array_column($chart['datasets'], 'label'));
        $this->assertSame(4, array_sum($chart['datasets'][0]['data']));
        $this->assertSame(1, array_sum($chart['datasets'][1]['data']));
        $this->assertSame(2, array_sum($chart['datasets'][2]['data']));
        $this->assertSame(array_keys($this->report()->columns()), array_keys($this->report()->formats()));
        $this->assertSame(ReportRow::FORMAT_MONEY, $this->report()->formats()['won_amount']);
    }

    #[Test]
    public function a_won_deal_whose_lead_is_out_of_the_viewer_scope_falls_under_no_source(): void
    {
        $website = LeadSource::query()->where('name_en', 'Website')->firstOrFail();
        $foreign = Lead::factory()->create(['owner_id' => $this->repA->getKey(), 'lead_source_id' => $website->getKey()]);
        $deal = Deal::factory()->create(['owner_id' => $this->repB->getKey(), 'amount' => 4321, 'lead_source_id' => null, 'lead_id' => $foreign->getKey()]);
        app(DealCloseService::class)->win($deal, DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->firstOrFail(), $this->repB);

        $admin = $this->report()->rows($this->admin, $this->filters())->keyBy(fn (ReportRow $row): string => $row->label);

        $this->assertSame(9321.0, $admin->get($this->label('Website'))?->number('won_amount'), 'an admin reads the lead, so the deal keeps its origin');

        $repB = $this->report()->rows($this->repB, $this->filters())->keyBy(fn (ReportRow $row): string => $row->label);

        $this->assertSame(4321.0, $repB->get(__('reports.labels.no_source'))?->number('won_amount'), 'the source of a lead the viewer may not read is never disclosed (D-4)');
        $this->assertSame(0.0, $repB->get($this->label('Website'))?->number('won_amount'));

        $foreign->delete();
        $trashed = $this->report()->rows($this->admin, $this->filters())->keyBy(fn (ReportRow $row): string => $row->label);

        $this->assertSame(5000.0, $trashed->get($this->label('Website'))?->number('won_amount'), 'a trashed lead contributes no source');
        $this->assertSame(4321.0, $trashed->get(__('reports.labels.no_source'))?->number('won_amount'));
    }

    private function label(string $nameEn): string
    {
        return LeadSource::query()->where('name_en', $nameEn)->firstOrFail()->display_name;
    }

    private function report(): SourcePerformanceReport
    {
        return app(SourcePerformanceReport::class);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function filters(array $data = [], ?User $viewer = null): ReportFilters
    {
        return ReportFilters::resolve(
            $data + ['from' => '2026-09-01', 'to' => '2026-09-30'],
            $viewer ?? $this->admin,
            app(RecordVisibilityResolver::class),
            Lead::permissionGroup(),
            'Asia/Riyadh',
        );
    }
}
