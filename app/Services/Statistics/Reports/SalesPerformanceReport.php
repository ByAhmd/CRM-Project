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
 * one visible deal matching the pipeline filter appear, plus an
 * "unassigned" line when unowned deals are in reach. A reopened deal is
 * open again and counts in neither column.
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
        $won = DealStatus::Won->value;
        $lost = DealStatus::Lost->value;
        $open = DealStatus::Open->value;

        $aggregates = [];

        foreach ($this->query($viewer, $filters)
            ->selectRaw('deals.owner_id AS owner_id')
            ->selectRaw('SUM(CASE WHEN deals.status = ? AND deals.won_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS won_count', [$won, $from, $to])
            ->selectRaw('SUM(CASE WHEN deals.status = ? AND deals.won_at BETWEEN ? AND ? THEN deals.amount ELSE 0 END) AS won_amount', [$won, $from, $to])
            ->selectRaw('SUM(CASE WHEN deals.status = ? AND deals.lost_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS lost_count', [$lost, $from, $to])
            ->selectRaw('SUM(CASE WHEN deals.status = ? AND deals.lost_at BETWEEN ? AND ? THEN deals.amount ELSE 0 END) AS lost_amount', [$lost, $from, $to])
            ->selectRaw('AVG(CASE WHEN deals.status = ? AND deals.won_at BETWEEN ? AND ? THEN DATEDIFF(deals.won_at, deals.created_at) END) AS cycle_days', [$won, $from, $to])
            ->selectRaw('SUM(CASE WHEN deals.status = ? THEN deals.amount ELSE 0 END) AS open_amount', [$open])
            ->groupBy('deals.owner_id')
            ->toBase()
            ->get() as $aggregate) {
            $wonCount = (int) $aggregate->won_count;
            $lostCount = (int) $aggregate->lost_count;
            $wonAmount = round((float) $aggregate->won_amount, 2);

            $aggregates[(int) ($aggregate->owner_id ?? 0)] = [
                'won_count' => $wonCount,
                'won_amount' => $wonAmount,
                'lost_count' => $lostCount,
                'lost_amount' => round((float) $aggregate->lost_amount, 2),
                'win_rate' => ReportRow::rate($wonCount, $wonCount + $lostCount),
                'avg_deal_size' => ReportRow::average($wonAmount, $wonCount, 2),
                'avg_cycle_days' => round((float) $aggregate->cycle_days, 1),
                'open_amount' => round((float) $aggregate->open_amount, 2),
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
