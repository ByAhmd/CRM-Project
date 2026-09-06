<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\LeadStatusKind;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Leads\ConversionRequest;
use App\Services\Leads\LeadConversionWorkflow;
use App\Services\Leads\LeadStatusWorkflow;
use App\Services\Statistics\Reports\LeadReport;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Lead report (module 23): leads created in the period by status, source
 * or owner, inside the viewer's scope (D-4, D-13).
 *
 * Fixture (September 2026, team Riyadh = manager + rep A; rep B has no team):
 * - rep A: three leads — one stays New (Website), one is qualified
 *   (Referral), one is qualified then converted (Website); plus one lead
 *   created in August (outside the period);
 * - rep B: two leads without a source — one stays New, one is qualified.
 */
final class LeadReportTest extends TestCase
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

        Lead::factory()->create(['owner_id' => $this->repA->getKey(), 'lead_source_id' => $website->getKey()]);
        $qualifiedLead = Lead::factory()->create(['owner_id' => $this->repA->getKey(), 'lead_source_id' => $referral->getKey()]);
        $convertedLead = Lead::factory()->create(['owner_id' => $this->repA->getKey(), 'lead_source_id' => $website->getKey()]);
        app(LeadStatusWorkflow::class)->transition($qualifiedLead, $qualified, $this->repA, 'Budget confirmed');
        app(LeadStatusWorkflow::class)->transition($convertedLead, $qualified, $this->repA, 'Budget confirmed');
        app(LeadConversionWorkflow::class)->convert($convertedLead, new ConversionRequest(accountMode: ConversionRequest::ACCOUNT_NONE), $this->repA);

        Lead::factory()->create(['owner_id' => $this->repB->getKey(), 'lead_source_id' => null]);
        $repBQualified = Lead::factory()->create(['owner_id' => $this->repB->getKey(), 'lead_source_id' => null]);
        app(LeadStatusWorkflow::class)->transition($repBQualified, $qualified, $this->repB, 'Budget confirmed');

        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        Lead::factory()->create(['owner_id' => $this->repA->getKey(), 'lead_source_id' => $website->getKey()]);
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
    }

    #[Test]
    public function an_admin_sees_every_lead_of_the_period_grouped_by_status_in_status_order(): void
    {
        $rows = $this->report()->rows($this->admin, $this->filters());

        $this->assertSame($this->statusLabels(), $rows->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame([
            ['leads' => 2, 'qualified' => 0, 'converted' => 0, 'conversion_rate' => 0.0],
            ['leads' => 0, 'qualified' => 0, 'converted' => 0, 'conversion_rate' => 0.0],
            ['leads' => 2, 'qualified' => 2, 'converted' => 0, 'conversion_rate' => 0.0],
            ['leads' => 1, 'qualified' => 1, 'converted' => 1, 'conversion_rate' => 100.0],
            ['leads' => 0, 'qualified' => 0, 'converted' => 0, 'conversion_rate' => 0.0],
        ], $rows->map(fn (ReportRow $row): array => $row->values)->all());

        $totals = $this->report()->totals($rows);

        $this->assertSame(__('reports.totals'), $totals->label);
        $this->assertSame(['leads' => 5, 'qualified' => 3, 'converted' => 1, 'conversion_rate' => 20.0], $totals->values);
    }

    #[Test]
    public function a_manager_sees_the_team_and_a_rep_sees_only_their_own_leads(): void
    {
        $manager = $this->report()->totals($this->report()->rows($this->manager, $this->filters()));
        $repA = $this->report()->totals($this->report()->rows($this->repA, $this->filters()));
        $repB = $this->report()->totals($this->report()->rows($this->repB, $this->filters()));

        $this->assertSame(['leads' => 3, 'qualified' => 2, 'converted' => 1, 'conversion_rate' => 33.3], $manager->values);
        $this->assertSame(['leads' => 3, 'qualified' => 2, 'converted' => 1, 'conversion_rate' => 33.3], $repA->values);
        $this->assertSame(['leads' => 2, 'qualified' => 1, 'converted' => 0, 'conversion_rate' => 0.0], $repB->values);
    }

    #[Test]
    public function the_period_excludes_leads_created_outside_it(): void
    {
        $august = $this->filters(['from' => '2026-08-01', 'to' => '2026-08-31']);

        $this->assertSame(1, (int) $this->report()->totals($this->report()->rows($this->admin, $august))->value('leads'));

        $both = $this->filters(['from' => '2026-08-01', 'to' => '2026-09-30']);

        $this->assertSame(6, (int) $this->report()->totals($this->report()->rows($this->admin, $both))->value('leads'));
    }

    #[Test]
    public function grouping_by_source_lists_active_sources_in_order_and_a_no_source_line(): void
    {
        $rows = $this->report()->rows($this->admin, $this->filters(['group_by' => LeadReport::GROUP_SOURCE]));
        $bySource = $rows->keyBy(fn (ReportRow $row): string => $row->label);

        $this->assertSame(LeadSource::query()->count() + 1, $rows->count());
        $this->assertSame(['leads' => 2, 'qualified' => 1, 'converted' => 1, 'conversion_rate' => 50.0], $bySource->get($this->sourceLabel('Website'))?->values);
        $this->assertSame(['leads' => 1, 'qualified' => 1, 'converted' => 0, 'conversion_rate' => 0.0], $bySource->get($this->sourceLabel('Referral'))?->values);
        $this->assertSame(['leads' => 2, 'qualified' => 1, 'converted' => 0, 'conversion_rate' => 0.0], $bySource->get(__('reports.labels.no_source'))?->values);
        $this->assertSame(__('reports.labels.no_source'), $rows->last()?->label);
        $this->assertSame(__('reports.filters.options.group_by.source'), $this->report()->labelHeading($this->filters(['group_by' => LeadReport::GROUP_SOURCE])));
    }

    #[Test]
    public function grouping_by_owner_lists_the_owners_by_name_and_only_those_in_scope(): void
    {
        $admin = $this->report()->rows($this->admin, $this->filters(['group_by' => LeadReport::GROUP_OWNER]));

        $this->assertSame([$this->repA->name, $this->repB->name], $admin->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(3, (int) $admin->first()?->value('leads'));
        $this->assertSame(2, (int) $admin->last()?->value('leads'));
        $this->assertSame(['group' => 'owner', 'id' => $this->repA->getKey()], $admin->first()?->meta);

        $manager = $this->report()->rows($this->manager, $this->filters(['group_by' => LeadReport::GROUP_OWNER]));

        $this->assertSame([$this->repA->name], $manager->map(fn (ReportRow $row): string => $row->label)->all());
    }

    #[Test]
    public function the_owner_filter_narrows_the_scope_and_never_widens_it(): void
    {
        $adminOnRepB = $this->report()->totals($this->report()->rows($this->admin, $this->filters(['owner_id' => $this->repB->getKey()])));
        $this->assertSame(2, (int) $adminOnRepB->value('leads'));

        $managerOnRepA = $this->report()->totals($this->report()->rows($this->manager, $this->filters(['owner_id' => $this->repA->getKey()], $this->manager)));
        $this->assertSame(3, (int) $managerOnRepA->value('leads'));

        $managerOnRepB = $this->filters(['owner_id' => $this->repB->getKey()], $this->manager);
        $this->assertNull($managerOnRepB->ownerId, 'rep B is outside the manager\'s assignable users, so the filter is dropped');
        $this->assertSame(3, (int) $this->report()->totals($this->report()->rows($this->manager, $managerOnRepB))->value('leads'));

        $repAOnRepB = $this->filters(['owner_id' => $this->repB->getKey()], $this->repA);
        $this->assertNull($repAOnRepB->ownerId);
        $this->assertSame(3, (int) $this->report()->totals($this->report()->rows($this->repA, $repAOnRepB))->value('leads'));

        // Even a forged owner id inside the DTO cannot reach past the scope.
        $forged = new ReportFilters($managerOnRepB->from, $managerOnRepB->to, ownerId: $this->repB->getKey());
        $this->assertSame(0, (int) $this->report()->totals($this->report()->rows($this->manager, $forged))->value('leads'));
    }

    #[Test]
    public function the_team_filter_narrows_an_admin_to_that_team(): void
    {
        $onTeam = $this->report()->totals($this->report()->rows($this->admin, $this->filters(['team_id' => $this->team->getKey()])));

        $this->assertSame(3, (int) $onTeam->value('leads'));

        $other = $this->makeTeam('Jeddah Team', 'فريق جدة');
        $onOther = $this->report()->totals($this->report()->rows($this->admin, $this->filters(['team_id' => $other->getKey()])));

        $this->assertSame(0, (int) $onOther->value('leads'));
    }

    #[Test]
    public function the_chart_carries_one_label_per_row_and_three_translated_datasets(): void
    {
        $chart = $this->report()->chart($this->admin, $this->filters());

        $this->assertSame($this->statusLabels(), $chart['labels']);
        $this->assertSame([__('reports.chart.leads'), __('reports.chart.qualified'), __('reports.chart.converted')], array_column($chart['datasets'], 'label'));
        $this->assertSame([2, 0, 2, 1, 0], $chart['datasets'][0]['data']);
        $this->assertSame([0, 0, 2, 1, 0], $chart['datasets'][1]['data']);
        $this->assertSame([0, 0, 0, 1, 0], $chart['datasets'][2]['data']);
        $this->assertSame(['primary', 'info', 'success'], array_column($chart['datasets'], 'color'));
    }

    #[Test]
    public function the_columns_and_formats_are_translated_and_aligned(): void
    {
        $columns = $this->report()->columns();

        $this->assertSame(['leads', 'qualified', 'converted', 'conversion_rate'], array_keys($columns));
        $this->assertSame(array_keys($columns), array_keys($this->report()->formats()));
        $this->assertSame(__('reports.columns.lead.conversion_rate'), $columns['conversion_rate']);
        $this->assertSame(ReportRow::FORMAT_PERCENT, $this->report()->formats()['conversion_rate']);
        $this->assertSame(__('reports.filters.options.group_by.status'), $this->report()->labelHeading($this->filters()));
    }

    /**
     * The seeded statuses in their order, named for the current locale (New, Contacted, Qualified, Converted, Unqualified).
     *
     * @return list<string>
     */
    private function statusLabels(): array
    {
        return LeadStatus::query()->orderBy('sort')->orderBy('id')->get()->map(fn (LeadStatus $status): string => $status->display_name)->all();
    }

    private function sourceLabel(string $nameEn): string
    {
        return LeadSource::query()->where('name_en', $nameEn)->firstOrFail()->display_name;
    }

    private function report(): LeadReport
    {
        return app(LeadReport::class);
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
            LeadReport::GROUP_BY,
            LeadReport::GROUP_STATUS,
        );
    }
}
