<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\CloseReasonKind;
use App\Enums\StageKind;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Deals\DealCloseService;
use App\Services\Deals\DealStageWorkflow;
use App\Services\Settings\PipelineService;
use App\Services\Statistics\Reports\PipelineReport;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Pipeline report (module 23): the open deals of a pipeline by stage —
 * count, amount, weighted amount, average age — inside the viewer's scope
 * (D-4, D-13).
 *
 * Fixture (today is 2026-09-15, team Riyadh = manager + rep A; rep B has no team):
 * - rep A: D1 in Qualification (10%), 1,000, created 10 days ago;
 *   D2 moved to Proposal with a 50% override, 2,000, created 20 days ago;
 *   a won deal (not open) and a deal in a second pipeline;
 * - rep B: D3 in Qualification, 4,000, created 30 days ago.
 */
final class PipelineReportTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    private User $manager;

    private User $repA;

    private User $repB;

    private Pipeline $other;

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
        $this->other = app(PipelineService::class)->create(['name_ar' => 'الشراكات', 'name_en' => 'Partnerships', 'is_active' => true, 'is_default' => false]);

        $this->travelTo(Carbon::parse('2026-09-05 12:00:00'));
        Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 1000]);

        $this->travelTo(Carbon::parse('2026-08-26 12:00:00'));
        $proposal = Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 2000, 'probability' => 50]);
        app(DealStageWorkflow::class)->transition($proposal, $this->stage(StageKind::Open, 1), $this->repA);

        $won = Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 9000]);
        app(DealCloseService::class)->win($won, DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->firstOrFail(), $this->repA);

        $otherStage = $this->other->stages()->where('is_default', true)->firstOrFail();
        Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 8000, 'pipeline_id' => $this->other->getKey(), 'stage_id' => $otherStage->getKey()]);

        $this->travelTo(Carbon::parse('2026-08-16 12:00:00'));
        Deal::factory()->create(['owner_id' => $this->repB->getKey(), 'amount' => 4000]);

        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
    }

    #[Test]
    public function an_admin_sees_every_open_stage_of_the_default_pipeline_with_counts_amounts_weights_and_ages(): void
    {
        $rows = $this->report()->rows($this->admin, $this->filters());

        $this->assertSame(
            PipelineStage::query()->where('pipeline_id', $this->defaultPipelineId())->where('kind', StageKind::Open->value)->orderBy('sort')->get()->map(fn (PipelineStage $stage): string => $stage->display_name)->all(),
            $rows->map(fn (ReportRow $row): string => $row->label)->all(),
        );
        $this->assertSame([
            ['deals' => 2, 'amount' => 5000.0, 'weighted' => 500.0, 'age_days' => 20.0],
            ['deals' => 1, 'amount' => 2000.0, 'weighted' => 1000.0, 'age_days' => 20.0],
            ['deals' => 0, 'amount' => 0.0, 'weighted' => 0.0, 'age_days' => 0.0],
        ], $rows->map(fn (ReportRow $row): array => $row->values)->all());
        $this->assertSame(10, $rows->first()?->meta['probability']);

        $totals = $this->report()->totals($rows);

        $this->assertSame(['deals' => 3, 'amount' => 7000.0, 'weighted' => 1500.0, 'age_days' => 20.0], $totals->values);
    }

    #[Test]
    public function a_manager_sees_the_team_and_reps_see_only_their_own_deals(): void
    {
        $manager = $this->report()->rows($this->manager, $this->filters());
        $repA = $this->report()->rows($this->repA, $this->filters());
        $repB = $this->report()->rows($this->repB, $this->filters());

        $this->assertSame([
            ['deals' => 1, 'amount' => 1000.0, 'weighted' => 100.0, 'age_days' => 10.0],
            ['deals' => 1, 'amount' => 2000.0, 'weighted' => 1000.0, 'age_days' => 20.0],
            ['deals' => 0, 'amount' => 0.0, 'weighted' => 0.0, 'age_days' => 0.0],
        ], $manager->map(fn (ReportRow $row): array => $row->values)->all());
        $this->assertSame($manager->map(fn (ReportRow $row): array => $row->values)->all(), $repA->map(fn (ReportRow $row): array => $row->values)->all());
        $this->assertSame([
            ['deals' => 1, 'amount' => 4000.0, 'weighted' => 400.0, 'age_days' => 30.0],
            ['deals' => 0, 'amount' => 0.0, 'weighted' => 0.0, 'age_days' => 0.0],
            ['deals' => 0, 'amount' => 0.0, 'weighted' => 0.0, 'age_days' => 0.0],
        ], $repB->map(fn (ReportRow $row): array => $row->values)->all());
        $this->assertSame(['deals' => 2, 'amount' => 3000.0, 'weighted' => 1100.0, 'age_days' => 15.0], $this->report()->totals($manager)->values);
    }

    #[Test]
    public function the_pipeline_filter_switches_the_pipeline_and_the_default_is_the_default_pipeline(): void
    {
        $this->assertSame($this->defaultPipelineId(), $this->report()->pipelineId($this->filters()));

        $other = $this->report()->rows($this->admin, $this->filters(['pipeline_id' => $this->other->getKey()]));

        $this->assertSame($this->other->stages()->where('kind', StageKind::Open->value)->count(), $other->count());
        $this->assertSame(['deals' => 1, 'amount' => 8000.0, 'weighted' => 800.0, 'age_days' => 20.0], $other->first()?->values);
        $this->assertSame(8000.0, $this->report()->totals($other)->number('amount'));
    }

    #[Test]
    public function the_owner_filter_narrows_and_never_widens(): void
    {
        $adminOnRepB = $this->report()->totals($this->report()->rows($this->admin, $this->filters(['owner_id' => $this->repB->getKey()])));

        $this->assertSame(4000.0, $adminOnRepB->number('amount'));

        $managerOnRepB = $this->filters(['owner_id' => $this->repB->getKey()], $this->manager);

        $this->assertNull($managerOnRepB->ownerId);
        $this->assertSame(3000.0, $this->report()->totals($this->report()->rows($this->manager, $managerOnRepB))->number('amount'));
    }

    #[Test]
    public function the_chart_plots_amount_against_weighted_amount_per_stage(): void
    {
        $chart = $this->report()->chart($this->admin, $this->filters());

        $this->assertCount(3, $chart['labels']);
        $this->assertSame([__('reports.chart.amount'), __('reports.chart.weighted')], array_column($chart['datasets'], 'label'));
        $this->assertSame([5000.0, 2000.0, 0.0], $chart['datasets'][0]['data']);
        $this->assertSame([500.0, 1000.0, 0.0], $chart['datasets'][1]['data']);
        $this->assertSame(ReportRow::FORMAT_MONEY, $this->report()->formats()['weighted']);
        $this->assertSame(ReportRow::FORMAT_DECIMAL, $this->report()->formats()['age_days']);
    }

    private function stage(StageKind $kind, int $position = 0): PipelineStage
    {
        return PipelineStage::query()
            ->where('pipeline_id', $this->defaultPipelineId())
            ->where('kind', $kind->value)
            ->orderBy('sort')
            ->orderBy('id')
            ->skip($position)
            ->firstOrFail();
    }

    private function defaultPipelineId(): int
    {
        return (int) Pipeline::query()->where('is_default', true)->value('id');
    }

    private function report(): PipelineReport
    {
        return app(PipelineReport::class);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function filters(array $data = [], ?User $viewer = null): ReportFilters
    {
        return ReportFilters::resolve($data, $viewer ?? $this->admin, app(RecordVisibilityResolver::class), Deal::permissionGroup(), 'Asia/Riyadh');
    }
}
