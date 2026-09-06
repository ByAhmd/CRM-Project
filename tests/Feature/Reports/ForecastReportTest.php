<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\CloseReasonKind;
use App\Enums\ForecastCategory;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Deals\DealCloseService;
use App\Services\Settings\PipelineService;
use App\Services\Statistics\Reports\ForecastReport;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Forecast report (module 23): open deals by expected close month and
 * forecast category, inside the viewer's scope (D-4, D-13).
 *
 * Fixture (today is 2026-09-15; the default stage has a 10% probability;
 * team Riyadh = manager + rep A; rep B has no team):
 * - rep A: D1 closes 20 Sep, pipeline, 1,000 (weighted 100); D2 closes
 *   5 Oct, commit, 2,000 with an 80% override (1,600); D3 closed on
 *   1 Sep — overdue — best case, 500 (50); a won deal (not open);
 * - rep B: D4 closes 1 Jun 2027 — later — pipeline, 4,000 (400); D5 has
 *   no close date, omitted, 300 (30).
 */
final class ForecastReportTest extends TestCase
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

        Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 1000, 'expected_close_date' => '2026-09-20', 'forecast_category' => ForecastCategory::Pipeline]);
        Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 2000, 'expected_close_date' => '2026-10-05', 'forecast_category' => ForecastCategory::Commit, 'probability' => 80]);
        Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 500, 'expected_close_date' => '2026-09-01', 'forecast_category' => ForecastCategory::BestCase]);
        $won = Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 7000, 'expected_close_date' => '2026-09-20', 'forecast_category' => ForecastCategory::Commit]);
        app(DealCloseService::class)->win($won, DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->firstOrFail(), $this->repA);

        Deal::factory()->create(['owner_id' => $this->repB->getKey(), 'amount' => 4000, 'expected_close_date' => '2027-06-01', 'forecast_category' => ForecastCategory::Pipeline]);
        Deal::factory()->create(['owner_id' => $this->repB->getKey(), 'amount' => 300, 'expected_close_date' => null, 'forecast_category' => ForecastCategory::Omitted]);
    }

    #[Test]
    public function an_admin_sees_the_overdue_bucket_six_months_and_the_later_and_unscheduled_lines(): void
    {
        $rows = $this->report()->rows($this->admin, $this->filters());

        $this->assertSame([
            ForecastReport::BUCKET_OVERDUE, '2026-09', '2026-10', '2026-11', '2026-12', '2027-01', '2027-02',
            ForecastReport::BUCKET_LATER, ForecastReport::BUCKET_UNSCHEDULED,
        ], $rows->map(fn (ReportRow $row): string => (string) $row->meta['bucket'])->all());

        // The month buckets are localised, never the raw ISO key.
        $this->assertSame([
            __('reports.labels.overdue'), ForecastReport::label('2026-09'), ForecastReport::label('2026-10'),
            ForecastReport::label('2026-11'), ForecastReport::label('2026-12'), ForecastReport::label('2027-01'),
            ForecastReport::label('2027-02'), __('reports.labels.later'), __('reports.labels.unscheduled'),
        ], $rows->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertNotSame('2026-09', ForecastReport::label('2026-09'));

        $overdue = $rows->get(0);
        $september = $rows->get(1);
        $october = $rows->get(2);
        $later = $rows->get(7);
        $unscheduled = $rows->get(8);

        $this->assertSame(['best_case_amount' => 500.0, 'best_case_weighted' => 50.0, 'total_amount' => 500.0, 'total_weighted' => 50.0], $this->nonZero($overdue->values ?? []));
        $this->assertSame(['pipeline_amount' => 1000.0, 'pipeline_weighted' => 100.0, 'total_amount' => 1000.0, 'total_weighted' => 100.0], $this->nonZero($september->values ?? []));
        $this->assertSame(['commit_amount' => 2000.0, 'commit_weighted' => 1600.0, 'total_amount' => 2000.0, 'total_weighted' => 1600.0], $this->nonZero($october->values ?? []));
        $this->assertSame([], $this->nonZero($rows->get(3)->values ?? []));
        $this->assertSame(['pipeline_amount' => 4000.0, 'pipeline_weighted' => 400.0, 'total_amount' => 4000.0, 'total_weighted' => 400.0], $this->nonZero($later->values ?? []));
        $this->assertSame(['omitted_amount' => 300.0, 'omitted_weighted' => 30.0, 'total_amount' => 300.0, 'total_weighted' => 30.0], $this->nonZero($unscheduled->values ?? []));
        $this->assertSame(['bucket' => ForecastReport::BUCKET_OVERDUE], $overdue?->meta);

        $totals = $this->report()->totals($rows);

        $this->assertSame(7800.0, $totals->number('total_amount'));
        $this->assertSame(2180.0, $totals->number('total_weighted'));
        $this->assertSame(5000.0, $totals->number('pipeline_amount'));
    }

    #[Test]
    public function a_manager_sees_the_team_without_the_optional_lines_and_reps_see_only_their_own_deals(): void
    {
        $manager = $this->report()->rows($this->manager, $this->filters());

        $this->assertCount(7, $manager);
        $this->assertSame(3500.0, $this->report()->totals($manager)->number('total_amount'));
        $this->assertSame(1750.0, $this->report()->totals($manager)->number('total_weighted'));

        $repA = $this->report()->rows($this->repA, $this->filters());

        $this->assertSame($manager->map(fn (ReportRow $row): array => $row->values)->all(), $repA->map(fn (ReportRow $row): array => $row->values)->all());

        $repB = $this->report()->rows($this->repB, $this->filters());

        $this->assertCount(9, $repB);
        $this->assertSame(4300.0, $this->report()->totals($repB)->number('total_amount'));
        $this->assertSame(0.0, $repB->get(1)?->number('total_amount'));
    }

    #[Test]
    public function the_pipeline_filter_switches_the_pipeline_and_an_empty_pipeline_still_lists_the_seven_fixed_buckets(): void
    {
        $other = app(PipelineService::class)->create(['name_ar' => 'الشراكات', 'name_en' => 'Partnerships', 'is_active' => true, 'is_default' => false]);
        $rows = $this->report()->rows($this->admin, $this->filters(['pipeline_id' => $other->getKey()]));

        $this->assertCount(7, $rows);
        $this->assertSame(0.0, $this->report()->totals($rows)->number('total_amount'));
        $this->assertSame($other->getKey(), $this->report()->pipelineId($this->filters(['pipeline_id' => $other->getKey()])));
        $this->assertNotSame($other->getKey(), $this->report()->pipelineId($this->filters()));
    }

    #[Test]
    public function the_owner_filter_narrows_and_never_widens(): void
    {
        $adminOnRepB = $this->report()->rows($this->admin, $this->filters(['owner_id' => $this->repB->getKey()]));

        $this->assertSame(4300.0, $this->report()->totals($adminOnRepB)->number('total_amount'));

        $managerOnRepB = $this->filters(['owner_id' => $this->repB->getKey()], $this->manager);

        $this->assertNull($managerOnRepB->ownerId);
        $this->assertSame(3500.0, $this->report()->totals($this->report()->rows($this->manager, $managerOnRepB))->number('total_amount'));
    }

    #[Test]
    public function the_chart_stacks_the_weighted_amount_per_category_and_the_columns_cover_every_category(): void
    {
        $chart = $this->report()->chart($this->admin, $this->filters());

        $this->assertCount(9, $chart['labels']);
        $this->assertSame(array_map(static fn (ForecastCategory $category): string => $category->getLabel(), ForecastCategory::cases()), array_column($chart['datasets'], 'label'));
        $this->assertSame(array_map(static fn (ForecastCategory $category): string => $category->getColor(), ForecastCategory::cases()), array_column($chart['datasets'], 'color'));
        $this->assertSame([0.0, 100.0, 0.0, 0.0, 0.0, 0.0, 0.0, 400.0, 0.0], $chart['datasets'][0]['data']);
        $this->assertSame([0.0, 0.0, 1600.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0], $chart['datasets'][2]['data']);

        $columns = $this->report()->columns();

        $this->assertCount(10, $columns);
        $this->assertSame(__('reports.columns.forecast.commit_weighted'), $columns['commit_weighted']);
        $this->assertSame(array_fill_keys(array_keys($columns), ReportRow::FORMAT_MONEY), $this->report()->formats());
    }

    /**
     * @param  array<string, int|float|string>  $values
     * @return array<string, int|float|string>
     */
    private function nonZero(array $values): array
    {
        return array_filter($values, static fn (int|float|string $value): bool => $value !== 0.0 && $value !== 0);
    }

    private function report(): ForecastReport
    {
        return app(ForecastReport::class);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function filters(array $data = [], ?User $viewer = null): ReportFilters
    {
        return ReportFilters::resolve($data, $viewer ?? $this->admin, app(RecordVisibilityResolver::class), Deal::permissionGroup(), 'Asia/Riyadh');
    }
}
