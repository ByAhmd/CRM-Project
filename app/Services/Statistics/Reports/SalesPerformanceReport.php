<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Sales performance (module 23, decision D-8): per owner, the deals won
 * and lost in the period (by their close date), the win rate, the average
 * won deal, the average sales cycle of the won deals (days from creation to
 * the win) and the amount still open in the pipeline right now.
 *
 * Owners are the viewer's visible deal owners (D-4, D-13): a rep sees one
 * line, a manager their team, an admin everyone — only owners with at least
 * one visible deal matching the pipeline filter that counts in a column (won
 * or lost in the period, or open now) appear, plus an "unassigned" line when
 * such unowned deals are in reach. A reopened deal is open again and counts
 * in neither the won nor the lost column.
 *
 * The rows read are bounded to the ones a column can count, so the report
 * grows with the open pipeline and the period, not with the whole deal
 * history (plan section 8). It is three grouped aggregates, each a bounded
 * read on its own index — open deals (deals_status_index), deals won in the
 * period (deals_won_at_index), deals lost in the period (deals_lost_at_index)
 * — merged per owner here. One aggregate with the period inside CASE
 * expressions read every deal, and an OR of the three conditions is not
 * merged by MySQL 8.4: it keeps a full scan of deals_owner_id_status_index
 * for the GROUP BY (step-13 EXPLAIN evidence).
 */
final class SalesPerformanceReport
{
    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
    ) {}

    /**
     * @return Collection<int, ReportRow>
     */
    public function rows(User $viewer, ReportFilters $filters): Collection
    {
        [$from, $to] = $filters->bounds();

        /** @var array<int, array{won_count: int, won_amount: float, lost_count: int, lost_amount: float, cycle_days: float, open_amount: float}> $totals */
        $totals = [];
        $empty = ['won_count' => 0, 'won_amount' => 0.0, 'lost_count' => 0, 'lost_amount' => 0.0, 'cycle_days' => 0.0, 'open_amount' => 0.0];

        foreach ($this->query($viewer, $filters)
            ->where('deals.status', DealStatus::Won->value)
            ->whereBetween('deals.won_at', [$from, $to])
            ->selectRaw('deals.owner_id AS owner_id, COUNT(*) AS won_count, SUM(deals.amount) AS won_amount, AVG(DATEDIFF(deals.won_at, deals.created_at)) AS cycle_days')
            ->groupBy('deals.owner_id')
            ->toBase()
            ->get() as $won) {
            $owner = (int) ($won->owner_id ?? 0);
            $totals[$owner] = ['won_count' => (int) $won->won_count, 'won_amount' => (float) $won->won_amount, 'cycle_days' => (float) $won->cycle_days] + ($totals[$owner] ?? $empty);
        }

        foreach ($this->query($viewer, $filters)
            ->where('deals.status', DealStatus::Lost->value)
            ->whereBetween('deals.lost_at', [$from, $to])
            ->selectRaw('deals.owner_id AS owner_id, COUNT(*) AS lost_count, SUM(deals.amount) AS lost_amount')
            ->groupBy('deals.owner_id')
            ->toBase()
            ->get() as $lost) {
            $owner = (int) ($lost->owner_id ?? 0);
            $totals[$owner] = ['lost_count' => (int) $lost->lost_count, 'lost_amount' => (float) $lost->lost_amount] + ($totals[$owner] ?? $empty);
        }

        foreach ($this->query($viewer, $filters)
            ->where('deals.status', DealStatus::Open->value)
            ->selectRaw('deals.owner_id AS owner_id, SUM(deals.amount) AS open_amount')
            ->groupBy('deals.owner_id')
            ->toBase()
            ->get() as $open) {
            $owner = (int) ($open->owner_id ?? 0);
            $totals[$owner] = ['open_amount' => (float) $open->open_amount] + ($totals[$owner] ?? $empty);
        }

        $aggregates = [];

        foreach ($totals as $owner => $total) {
            $wonAmount = round($total['won_amount'], 2);

            $aggregates[$owner] = [
                'won_count' => $total['won_count'],
                'won_amount' => $wonAmount,
                'lost_count' => $total['lost_count'],
                'lost_amount' => round($total['lost_amount'], 2),
                'win_rate' => ReportRow::rate($total['won_count'], $total['won_count'] + $total['lost_count']),
                'avg_deal_size' => ReportRow::average($wonAmount, $total['won_count'], 2),
                'avg_cycle_days' => round($total['cycle_days'], 1),
                'open_amount' => round($total['open_amount'], 2),
            ];
        }

        $rows = new Collection;

        foreach (User::query()->withTrashed()->whereIn('id', array_filter(array_keys($aggregates)))->orderBy('name')->get() as $owner) {
            $rows->push(new ReportRow($owner->name, $aggregates[(int) $owner->getKey()], ['owner_id' => (int) $owner->getKey()]));
        }

        if (isset($aggregates[0])) {
            $rows->push(new ReportRow(__('reports.labels.unassigned'), $aggregates[0], ['owner_id' => null]));
        }

        return $rows;
    }

    /**
     * @param  Collection<int, ReportRow>  $rows
     */
    public function totals(Collection $rows): ReportRow
    {
        $totals = ReportRow::totals(__('reports.totals'), $rows, ['won_count', 'won_amount', 'lost_count', 'lost_amount', 'open_amount']);
        $wonCount = $totals->number('won_count');
        $cycleSum = $rows->sum(static fn (ReportRow $row): float => $row->number('avg_cycle_days') * $row->number('won_count'));

        return new ReportRow($totals->label, [
            ...$totals->values,
            'win_rate' => ReportRow::rate($wonCount, $wonCount + $totals->number('lost_count')),
            'avg_deal_size' => ReportRow::average($totals->number('won_amount'), $wonCount, 2),
            'avg_cycle_days' => ReportRow::average($cycleSum, $wonCount),
        ]);
    }

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int|float>, color: string}>}
     */
    public function chart(User $viewer, ReportFilters $filters): array
    {
        return $this->chartFromRows($this->rows($viewer, $filters));
    }

    /**
     * The same chart from rows already fetched, so a page render does not
     * repeat the aggregates (D-1).
     *
     * @param  Collection<int, ReportRow>  $rows
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int|float>, color: string}>}
     */
    public function chartFromRows(Collection $rows): array
    {

        return [
            'labels' => $rows->map(static fn (ReportRow $row): string => $row->label)->values()->all(),
            'datasets' => [
                ['label' => __('reports.chart.won_amount'), 'data' => $rows->map(static fn (ReportRow $row): float => $row->number('won_amount'))->values()->all(), 'color' => 'success'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'won_count' => __('reports.columns.sales.won_count'),
            'won_amount' => __('reports.columns.sales.won_amount'),
            'lost_count' => __('reports.columns.sales.lost_count'),
            'lost_amount' => __('reports.columns.sales.lost_amount'),
            'win_rate' => __('reports.columns.sales.win_rate'),
            'avg_deal_size' => __('reports.columns.sales.avg_deal_size'),
            'avg_cycle_days' => __('reports.columns.sales.avg_cycle_days'),
            'open_amount' => __('reports.columns.sales.open_amount'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function formats(): array
    {
        return [
            'won_count' => ReportRow::FORMAT_COUNT,
            'won_amount' => ReportRow::FORMAT_MONEY,
            'lost_count' => ReportRow::FORMAT_COUNT,
            'lost_amount' => ReportRow::FORMAT_MONEY,
            'win_rate' => ReportRow::FORMAT_PERCENT,
            'avg_deal_size' => ReportRow::FORMAT_MONEY,
            'avg_cycle_days' => ReportRow::FORMAT_DECIMAL,
            'open_amount' => ReportRow::FORMAT_MONEY,
        ];
    }

    /**
     * @return Builder<Deal>
     */
    private function query(User $viewer, ReportFilters $filters): Builder
    {
        return $filters->scope($viewer, $this->visibility, Deal::query())
            ->when($filters->pipelineId !== null, static fn (Builder $query): Builder => $query->where('deals.pipeline_id', $filters->pipelineId));
    }
}
