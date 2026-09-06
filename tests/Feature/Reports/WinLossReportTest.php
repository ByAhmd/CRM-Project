<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\CloseReasonKind;
use App\Enums\DealStatus;
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
use App\Services\Statistics\Reports\WinLossReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Win / loss report (module 23): deals closed in the period per month and
 * per close reason, inside the viewer's scope (D-4, D-13).
 *
 * Fixture (team Riyadh = manager + rep A; rep B has no team):
 * - rep A: won 5 Sep for reason W1 (1,000), won 20 Sep for reason W1
 *   (3,000), lost 8 Sep for reason L1 (500);
 * - rep B: lost 25 Aug for reason L2 (700), won 2 Sep for reason W2 (2,000);
 * - rep A: a deal won then reopened (appears nowhere).
 */
final class WinLossReportTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    private User $manager;

    private User $repA;

    private User $repB;

    private DealCloseReason $w1;

    private DealCloseReason $w2;

    private DealCloseReason $l1;

    private DealCloseReason $l2;

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

        [$this->w1, $this->w2] = DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->orderBy('sort')->limit(2)->get()->all();
        [$this->l1, $this->l2] = DealCloseReason::query()->where('kind', CloseReasonKind::Lost->value)->orderBy('sort')->limit(2)->get()->all();
        $close = app(DealCloseService::class);

        $this->travelTo(Carbon::parse('2026-08-25 10:00:00'));
        $close->lose(Deal::factory()->create(['owner_id' => $this->repB->getKey(), 'amount' => 700]), $this->l2, $this->repB);

        $this->travelTo(Carbon::parse('2026-09-02 10:00:00'));
        $close->win(Deal::factory()->create(['owner_id' => $this->repB->getKey(), 'amount' => 2000]), $this->w2, $this->repB);

        $this->travelTo(Carbon::parse('2026-09-05 10:00:00'));
        $close->win(Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 1000]), $this->w1, $this->repA);
        $reopened = $close->win(Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 9000]), $this->w1, $this->repA);
        $close->reopen($reopened, $this->repA);

        $this->travelTo(Carbon::parse('2026-09-08 10:00:00'));
        $close->lose(Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 500]), $this->l1, $this->repA);

        $this->travelTo(Carbon::parse('2026-09-20 10:00:00'));
        $close->win(Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 3000]), $this->w1, $this->repA);

        $this->travelTo(Carbon::parse('2026-09-25 12:00:00'));
    }

    #[Test]
    public function an_admin_sees_the_reasons_won_first_by_count_with_their_share_of_the_outcome(): void
    {
        $rows = $this->report()->rows($this->admin, $this->filters());

        $first = $rows->get(0);

        $this->assertNotNull($first);
        $this->assertSame([$this->w1->display_name, $this->w2->display_name, $this->l1->display_name], $rows->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(['kind' => DealStatus::Won->getLabel(), 'deals' => 2, 'amount' => 4000.0, 'share' => 66.7], $first->values);
        $this->assertSame(['kind' => DealStatus::Won->getLabel(), 'deals' => 1, 'amount' => 2000.0, 'share' => 33.3], $rows->get(1)?->values);
        $this->assertSame(['kind' => DealStatus::Lost->getLabel(), 'deals' => 1, 'amount' => 500.0, 'share' => 100.0], $rows->get(2)?->values);
        $this->assertSame(['status' => 'won', 'color' => 'success', 'reason_id' => $this->w1->getKey()], $first->meta);

        $totals = $this->report()->totals($rows);

        $this->assertSame(['deals' => 4, 'amount' => 6500.0, 'kind' => '', 'share' => ''], $totals->values);
    }

    #[Test]
    public function the_monthly_series_is_zero_filled_across_the_period_and_feeds_the_line_chart(): void
    {
        $filters = $this->filters(['from' => '2026-07-01', 'to' => '2026-09-30']);
        $months = $this->report()->monthly($this->admin, $filters);

        $this->assertSame(['2026-07', '2026-08', '2026-09'], $months->map(fn (ReportRow $row): string => (string) $row->meta['month'])->all());
        $this->assertSame(
            [WinLossReport::monthLabel('2026-07'), WinLossReport::monthLabel('2026-08'), WinLossReport::monthLabel('2026-09')],
            $months->map(fn (ReportRow $row): string => $row->label)->all(),
            'the month label is a calendar month, never the raw ISO key',
        );
        $this->assertNotSame('2026-07', WinLossReport::monthLabel('2026-07'));
        $this->assertSame(['won_count' => 0, 'won_amount' => 0.0, 'lost_count' => 0, 'lost_amount' => 0.0], $months->get(0)?->values);
        $this->assertSame(['won_count' => 0, 'won_amount' => 0.0, 'lost_count' => 1, 'lost_amount' => 700.0], $months->get(1)?->values);
        $this->assertSame(['won_count' => 3, 'won_amount' => 6000.0, 'lost_count' => 1, 'lost_amount' => 500.0], $months->get(2)?->values);

        $chart = $this->report()->chart($this->admin, $filters);

        $this->assertSame(
            [WinLossReport::monthLabel('2026-07'), WinLossReport::monthLabel('2026-08'), WinLossReport::monthLabel('2026-09')],
            $chart['labels'],
        );
        $this->assertSame([__('reports.chart.won'), __('reports.chart.lost')], array_column($chart['datasets'], 'label'));
        $this->assertSame([0, 0, 3], $chart['datasets'][0]['data']);
        $this->assertSame([0, 1, 1], $chart['datasets'][1]['data']);
        $this->assertSame(['success', 'danger'], array_column($chart['datasets'], 'color'));
    }

    #[Test]
    public function a_manager_sees_the_team_and_reps_see_only_their_own_deals(): void
    {
        $manager = $this->report()->rows($this->manager, $this->filters());
        $repB = $this->report()->rows($this->repB, $this->filters(['from' => '2026-08-01', 'to' => '2026-09-30']));

        $this->assertSame([$this->w1->display_name, $this->l1->display_name], $manager->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(100.0, $manager->first()?->number('share'));
        $this->assertSame([0, 3], $this->report()->monthly($this->manager, $this->filters(['from' => '2026-08-01', 'to' => '2026-09-30']))->map(fn (ReportRow $row): int => (int) $row->value('won_count') + (int) $row->value('lost_count'))->all());

        $this->assertSame([$this->w2->display_name, $this->l2->display_name], $repB->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame([1, 1], $this->report()->monthly($this->repB, $this->filters(['from' => '2026-08-01', 'to' => '2026-09-30']))->map(fn (ReportRow $row): int => (int) $row->value('won_count') + (int) $row->value('lost_count'))->all());
    }

    #[Test]
    public function the_owner_filter_narrows_and_never_widens(): void
    {
        $adminOnRepB = $this->report()->rows($this->admin, $this->filters(['owner_id' => $this->repB->getKey()]));

        $this->assertSame([$this->w2->display_name], $adminOnRepB->map(fn (ReportRow $row): string => $row->label)->all());

        $managerOnRepB = $this->filters(['owner_id' => $this->repB->getKey()], $this->manager);

        $this->assertNull($managerOnRepB->ownerId);
        $this->assertSame(3, $this->report()->rows($this->manager, $managerOnRepB)->sum(fn (ReportRow $row): int => (int) $row->value('deals')));
    }

    #[Test]
    public function a_reopened_deal_counts_nowhere_and_the_columns_are_translated(): void
    {
        $rows = $this->report()->rows($this->admin, $this->filters());

        $this->assertSame(6500.0, $rows->sum(fn (ReportRow $row): float => $row->number('amount')));
        $this->assertSame(['kind', 'deals', 'amount', 'share'], array_keys($this->report()->columns()));
        $this->assertSame(__('reports.columns.win_loss.share'), $this->report()->columns()['share']);
        $this->assertSame(ReportRow::FORMAT_TEXT, $this->report()->formats()['kind']);
    }

    #[Test]
    public function the_report_covers_every_pipeline_until_one_is_chosen(): void
    {
        $default = (int) Pipeline::query()->where('is_default', true)->value('id');
        $other = app(PipelineService::class)->create(['name_ar' => 'الشراكات', 'name_en' => 'Partnerships', 'is_active' => true, 'is_default' => false]);
        app(DealCloseService::class)->win(
            Deal::factory()->create(['owner_id' => $this->repA->getKey(), 'amount' => 9000, 'pipeline_id' => $other->getKey()]),
            $this->w1,
            $this->repA,
        );

        $everything = $this->report()->totals($this->report()->rows($this->admin, $this->filters()));

        $this->assertSame(5, (int) $everything->value('deals'), 'no pipeline filter means every pipeline (D-13)');
        $this->assertSame(15500.0, $everything->number('amount'));

        $narrowed = $this->report()->totals($this->report()->rows($this->admin, $this->filters(['pipeline_id' => $other->getKey()])));

        $this->assertSame(1, (int) $narrowed->value('deals'));
        $this->assertSame(9000.0, $narrowed->number('amount'));

        $original = $this->report()->totals($this->report()->rows($this->admin, $this->filters(['pipeline_id' => $default])));

        $this->assertSame(4, (int) $original->value('deals'));
        $this->assertSame(6500.0, $original->number('amount'));
    }

    private function report(): WinLossReport
    {
        return app(WinLossReport::class);
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
