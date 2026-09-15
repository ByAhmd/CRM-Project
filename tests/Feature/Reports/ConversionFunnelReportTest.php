<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\LeadStatusKind;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Leads\ConversionRequest;
use App\Services\Leads\LeadConversionWorkflow;
use App\Services\Leads\LeadStatusWorkflow;
use App\Services\Statistics\Reports\ConversionFunnelReport;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Conversion funnel (module 23): leads entering New → Contacted →
 * Qualified → Converted during the period, once per stage, inside the
 * viewer's scope (D-4, D-13).
 *
 * Fixture (September 2026, team Riyadh = manager + rep A; rep B has no team):
 * - L1 (rep A): created, contacted, qualified, converted — every stage;
 * - L2 (rep A): created, contacted, back to New, contacted again — New and
 *   Contacted once each;
 * - L3 (rep A): created in August, contacted in September — Contacted only;
 * - L4 (rep B): created, qualified straight away — New and Qualified.
 */
final class ConversionFunnelReportTest extends TestCase
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

        $new = $this->statusOfKind(LeadStatusKind::New);
        $contacted = $this->statusOfKind(LeadStatusKind::Working);
        $qualified = $this->statusOfKind(LeadStatusKind::Qualified);
        $workflow = app(LeadStatusWorkflow::class);

        $this->travelTo(Carbon::parse('2026-08-10 09:00:00'));
        $l3 = Lead::factory()->create(['owner_id' => $this->repA->getKey()]);

        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));
        $l1 = Lead::factory()->create(['owner_id' => $this->repA->getKey()]);
        $l2 = Lead::factory()->create(['owner_id' => $this->repA->getKey()]);
        $l4 = Lead::factory()->create(['owner_id' => $this->repB->getKey()]);

        $workflow->transition($l1, $contacted, $this->repA);
        $workflow->transition($l1, $qualified, $this->repA, 'Budget confirmed');
        app(LeadConversionWorkflow::class)->convert($l1, new ConversionRequest(accountMode: ConversionRequest::ACCOUNT_NONE), $this->repA);

        $workflow->transition($l2, $contacted, $this->repA);
        $workflow->transition($l2, $new, $this->repA);
        $workflow->transition($l2, $contacted, $this->repA);

        $workflow->transition($l3, $contacted, $this->repA);
        $workflow->transition($l4, $qualified, $this->repB, 'Budget confirmed');

        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
    }

    #[Test]
    public function an_admin_sees_the_four_stages_with_each_lead_counted_once_per_stage(): void
    {
        $rows = $this->report()->rows($this->admin, $this->filters());

        $this->assertSame(
            array_map(static fn (LeadStatusKind $kind): string => $kind->getLabel(), ConversionFunnelReport::STAGES),
            $rows->map(fn (ReportRow $row): string => $row->label)->all(),
        );
        $this->assertSame([
            ['leads' => 3, 'step_rate' => 100.0, 'overall_rate' => 100.0],
            ['leads' => 3, 'step_rate' => 100.0, 'overall_rate' => 100.0],
            ['leads' => 2, 'step_rate' => 66.7, 'overall_rate' => 66.7],
            ['leads' => 1, 'step_rate' => 50.0, 'overall_rate' => 33.3],
        ], $rows->map(fn (ReportRow $row): array => $row->values)->all());
        $this->assertSame(['kind' => 'converted', 'color' => LeadStatusKind::Converted->getColor()], $rows->last()?->meta);
    }

    #[Test]
    public function a_manager_sees_the_team_and_reps_see_only_their_own_leads(): void
    {
        $manager = $this->report()->rows($this->manager, $this->filters());
        $repA = $this->report()->rows($this->repA, $this->filters());
        $repB = $this->report()->rows($this->repB, $this->filters());

        $this->assertSame([2, 3, 1, 1], $manager->map(fn (ReportRow $row): int => (int) $row->value('leads'))->all());
        $this->assertSame([100.0, 150.0, 33.3, 100.0], $manager->map(fn (ReportRow $row): float => (float) $row->value('step_rate'))->all());
        $this->assertSame([100.0, 150.0, 50.0, 50.0], $manager->map(fn (ReportRow $row): float => (float) $row->value('overall_rate'))->all());

        $this->assertSame([2, 3, 1, 1], $repA->map(fn (ReportRow $row): int => (int) $row->value('leads'))->all());
        $this->assertSame([1, 0, 1, 0], $repB->map(fn (ReportRow $row): int => (int) $row->value('leads'))->all());
        $this->assertSame([100.0, 0.0, 0.0, 0.0], $repB->map(fn (ReportRow $row): float => (float) $row->value('step_rate'))->all());
    }

    #[Test]
    public function the_period_bounds_the_transitions_and_the_creations(): void
    {
        $august = $this->report()->rows($this->admin, $this->filters(['from' => '2026-08-01', 'to' => '2026-08-31']));

        $this->assertSame([1, 0, 0, 0], $august->map(fn (ReportRow $row): int => (int) $row->value('leads'))->all());

        $lateSeptember = $this->report()->rows($this->admin, $this->filters(['from' => '2026-09-10', 'to' => '2026-09-30']));

        $this->assertSame([0, 0, 0, 0], $lateSeptember->map(fn (ReportRow $row): int => (int) $row->value('leads'))->all());
        $this->assertSame([0.0, 0.0, 0.0, 0.0], $lateSeptember->map(fn (ReportRow $row): float => (float) $row->value('overall_rate'))->all());
    }

    #[Test]
    public function the_owner_filter_narrows_and_never_widens(): void
    {
        $adminOnRepB = $this->report()->rows($this->admin, $this->filters(['owner_id' => $this->repB->getKey()]));

        $this->assertSame([1, 0, 1, 0], $adminOnRepB->map(fn (ReportRow $row): int => (int) $row->value('leads'))->all());

        $managerOnRepB = $this->filters(['owner_id' => $this->repB->getKey()], $this->manager);

        $this->assertNull($managerOnRepB->ownerId);
        $this->assertSame([2, 3, 1, 1], $this->report()->rows($this->manager, $managerOnRepB)->map(fn (ReportRow $row): int => (int) $row->value('leads'))->all());
    }

    #[Test]
    public function the_chart_is_a_single_dataset_over_the_four_stages(): void
    {
        $chart = $this->report()->chart($this->admin, $this->filters());

        $this->assertCount(4, $chart['labels']);
        $this->assertCount(1, $chart['datasets']);
        $this->assertSame(__('reports.chart.leads'), $chart['datasets'][0]['label']);
        $this->assertSame([3, 3, 2, 1], $chart['datasets'][0]['data']);
        $this->assertSame(['leads', 'step_rate', 'overall_rate'], array_keys($this->report()->columns()));
        $this->assertSame(ReportRow::FORMAT_PERCENT, $this->report()->formats()['overall_rate']);
    }

    private function report(): ConversionFunnelReport
    {
        return app(ConversionFunnelReport::class);
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
