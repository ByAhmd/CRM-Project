<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\CloseReasonKind;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\Pipeline;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Deals\DealCloseService;
use App\Services\Settings\PipelineService;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use App\Services\Statistics\Reports\SalesPerformanceReport;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Sales performance (module 23): won and lost deals per owner in the
 * period, win rate, average deal, sales cycle and open pipeline, inside
 * the viewer's scope (D-4, D-13).
 *
 * Fixture (September 2026, team Riyadh = manager + rep A; rep B has no team):
 * - rep A: W1 created 1 Aug won 5 Sep (1,000, 35 days); W2 created 1 Sep
 *   won 11 Sep (3,000, 10 days); L1 lost 8 Sep (500); O1 open (700);
 *   a deal won in August (outside the period);
 * - rep B: W3 created 10 Sep won 12 Sep (2,000, 2 days).
 */
final class SalesPerformanceReportTest extends TestCase
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

        $this->team = $this->makeTeam();
        $this->admin = $this->admin();
        $this->manager = $this->salesManager($this->team);
        $this->repA = $this->salesRep($this->team);
        $this->repB = $this->salesRep();

        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));
        $w1 = Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 1000]);
        $august = Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 6000]);

        $this->travelTo(Carbon::parse('2026-08-20 10:00:00'));
        $this->win($august, $this->repA);

        $this->travelTo(Carbon::parse('2026-09-01 10:00:00'));
        $w2 = Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 3000]);
        $l1 = Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 500]);
        Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 700]);

        $this->travelTo(Carbon::parse('2026-09-05 10:00:00'));
        $this->win($w1, $this->repA);

        $this->travelTo(Carbon::parse('2026-09-08 10:00:00'));
        app(DealCloseService::class)->lose($l1, DealCloseReason::query()->where('kind', CloseReasonKind::Lost->value)->firstOrFail(), $this->repA);

        $this->travelTo(Carbon::parse('2026-09-10 10:00:00'));
        $w3 = Deal::factory()->create(['owner_id' => $this->repB->getKey(), 'amount' => 2000]);

        $this->travelTo(Carbon::parse('2026-09-11 10:00:00'));
        $this->win($w2, $this->repA);

        $this->travelTo(Carbon::parse('2026-09-12 10:00:00'));
        $this->win($w3, $this->repB);

        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
    }

    #[Test]
    public function an_admin_sees_one_line_per_owner_with_exact_figures_and_totals(): void
    {
        $rows = $this->report()->rows($this->admin, $this->filters());
        $first = $rows->first();
        $last = $rows->last();

        $this->assertNotNull($first);
        $this->assertSame([$this->repA->name, $this->repB->name], $rows->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame([
            'won_count' => 2, 'won_amount' => 4000.0, 'lost_count' => 1, 'lost_amount' => 500.0,
            'win_rate' => 66.7, 'avg_deal_size' => 2000.0, 'avg_cycle_days' => 22.5, 'open_amount' => 700.0,
        ], $first->values);
        $this->assertSame([
            'won_count' => 1, 'won_amount' => 2000.0, 'lost_count' => 0, 'lost_amount' => 0.0,
            'win_rate' => 100.0, 'avg_deal_size' => 2000.0, 'avg_cycle_days' => 2.0, 'open_amount' => 0.0,
        ], $last?->values);
        $this->assertSame(['owner_id' => $this->repA->getKey()], $first->meta);

        $totals = $this->report()->totals($rows);

        $this->assertSame([
            'won_count' => 3, 'won_amount' => 6000.0, 'lost_count' => 1, 'lost_amount' => 500.0, 'open_amount' => 700.0,
            'win_rate' => 75.0, 'avg_deal_size' => 2000.0, 'avg_cycle_days' => 15.7,
        ], $totals->values);
    }

    #[Test]
    public function a_manager_sees_the_team_and_reps_see_only_themselves(): void
    {
        $manager = $this->report()->rows($this->manager, $this->filters());
        $repA = $this->report()->rows($this->repA, $this->filters());
        $repB = $this->report()->rows($this->repB, $this->filters());

        $managerRow = $manager->first();
        $repARow = $repA->first();
        $repBRow = $repB->first();

        $this->assertNotNull($managerRow);
        $this->assertNotNull($repARow);

        $this->assertSame([$this->repA->name], $manager->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(4000.0, $managerRow->number('won_amount'));
        $this->assertSame($managerRow->values, $repARow->values);
        $this->assertSame([$this->repB->name], $repB->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(2000.0, $repBRow?->number('won_amount'));
    }

    #[Test]
    public function the_period_follows_the_close_date_and_the_open_pipeline_ignores_it(): void
    {
        $august = $this->report()->rows($this->admin, $this->filters(['from' => '2026-08-01', 'to' => '2026-08-31']));

        // Rep B's only deal was won in September and nothing of theirs is open: no column counts it in August.
        $this->assertSame([$this->repA->name], $august->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(['won_count' => 1, 'won_amount' => 6000.0, 'lost_count' => 0, 'lost_amount' => 0.0, 'win_rate' => 100.0, 'avg_deal_size' => 6000.0, 'avg_cycle_days' => 19.0, 'open_amount' => 700.0], $august->first()?->values);
    }

    #[Test]
    public function each_aggregate_reads_only_the_deals_its_columns_count_through_a_bounded_condition(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'from `deals`') && str_contains($query->sql, 'group by `deals`.`owner_id`')) {
                $queries[] = $query->sql;
            }
        });

        $this->report()->rows($this->admin, $this->filters());

        // Won in the period, lost in the period, open now: each a sargable condition on its own index, never an OR
        // over them and never a period that only a CASE expression sees (which aggregates every deal ever created).
        $this->assertCount(3, $queries, implode("\n", $queries));
        $this->assertMatchesRegularExpression('/where `deals`\.`status` = \? and `deals`\.`won_at` between \? and \?/', $queries[0]);
        $this->assertMatchesRegularExpression('/where `deals`\.`status` = \? and `deals`\.`lost_at` between \? and \?/', $queries[1]);
        $this->assertMatchesRegularExpression('/where `deals`\.`status` = \?/', $queries[2]);

        foreach ($queries as $sql) {
            $this->assertStringNotContainsString(' or ', strtolower($sql), $sql);
            $this->assertStringNotContainsString('case when', strtolower($sql), $sql);
        }
    }

    #[Test]
    public function an_owner_with_only_closed_deals_outside_the_period_and_nothing_open_has_no_line(): void
    {
        $august = $this->report()->rows($this->repB, $this->filters(['from' => '2026-08-01', 'to' => '2026-08-31'], $this->repB));

        $this->assertTrue($august->isEmpty(), 'every column of such a line would be zero');

        $september = $this->report()->rows($this->repB, $this->filters([], $this->repB));

        $this->assertSame([$this->repB->name], $september->map(fn (ReportRow $row): string => $row->label)->all());
    }

    #[Test]
    public function the_owner_and_pipeline_filters_narrow_and_never_widen(): void
    {
        $adminOnRepB = $this->report()->rows($this->admin, $this->filters(['owner_id' => $this->repB->getKey()]));

        $this->assertSame([$this->repB->name], $adminOnRepB->map(fn (ReportRow $row): string => $row->label)->all());

        $managerOnRepB = $this->filters(['owner_id' => $this->repB->getKey()], $this->manager);

        $this->assertNull($managerOnRepB->ownerId);
        $this->assertSame([$this->repA->name], $this->report()->rows($this->manager, $managerOnRepB)->map(fn (ReportRow $row): string => $row->label)->all());

        $unknownPipeline = $this->report()->rows($this->admin, $this->filters(['pipeline_id' => 999999]));

        $this->assertTrue($unknownPipeline->isEmpty());
    }

    #[Test]
    public function the_chart_plots_the_won_amount_per_owner(): void
    {
        $chart = $this->report()->chart($this->admin, $this->filters());

        $this->assertSame([$this->repA->name, $this->repB->name], $chart['labels']);
        $this->assertCount(1, $chart['datasets']);
        $this->assertSame(__('reports.chart.won_amount'), $chart['datasets'][0]['label']);
        $this->assertSame([4000.0, 2000.0], $chart['datasets'][0]['data']);
        $this->assertSame(array_keys($this->report()->columns()), array_keys($this->report()->formats()));
        $this->assertSame(ReportRow::FORMAT_PERCENT, $this->report()->formats()['win_rate']);
    }

    #[Test]
    public function the_report_covers_every_pipeline_until_one_is_chosen(): void
    {
        $default = (int) Pipeline::query()->where('is_default', true)->value('id');
        $other = app(PipelineService::class)->create(['name_ar' => 'الشراكات', 'name_en' => 'Partnerships', 'is_active' => true, 'is_default' => false]);
        $this->win(Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 9000, 'pipeline_id' => $other->getKey()]), $this->repA);

        $everything = $this->report()->rows($this->admin, $this->filters())->firstOrFail();

        $this->assertSame(3, (int) $everything->value('won_count'), 'no pipeline filter means every pipeline (D-13)');
        $this->assertSame(13000.0, $everything->number('won_amount'));

        $narrowed = $this->report()->rows($this->admin, $this->filters(['pipeline_id' => $other->getKey()]))->firstOrFail();

        $this->assertSame(1, (int) $narrowed->value('won_count'));
        $this->assertSame(9000.0, $narrowed->number('won_amount'));

        $original = $this->report()->rows($this->admin, $this->filters(['pipeline_id' => $default]))->firstOrFail();

        $this->assertSame(2, (int) $original->value('won_count'));
        $this->assertSame(4000.0, $original->number('won_amount'));
    }

    private function win(Deal $deal, User $actor): void
    {
        app(DealCloseService::class)->win($deal, DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->firstOrFail(), $actor);
    }

    private function report(): SalesPerformanceReport
    {
        return app(SalesPerformanceReport::class);
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
            Deal::permissionGroup(),
            'Asia/Riyadh',
        );
    }
}
