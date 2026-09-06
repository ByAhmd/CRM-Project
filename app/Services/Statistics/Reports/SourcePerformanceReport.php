<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Illuminate\Support\Collection;

/**
 * Source performance (module 23, decision D-7): per lead source, the leads
 * created in the period, how many were qualified and converted, and the
 * deals won in the period that came from the source — by the deal's own
 * source, else the source of the lead it was converted from — with their
 * amount and the lead conversion rate.
 *
 * Leads and deals are each read inside the viewer's visibility scope of
 * that entity (D-4, D-13) — including the lead a won deal came from, which
 * is joined as a scoped subquery so a deal never discloses the source of a
 * lead its viewer may not read, and a trashed lead contributes nothing.
 * Every active source is listed in its order,
 * zero-filled; an inactive source stays while it still carries figures, and
 * a "no source" line appears when leads or deals lack one.
 */
final class SourcePerformanceReport
{
    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
    ) {}

    /**
     * @return Collection<int, ReportRow>
     */
    public function rows(User $viewer, ReportFilters $filters): Collection
    {
        $empty = ['leads' => 0, 'qualified' => 0, 'converted' => 0, 'deals_won' => 0, 'won_amount' => 0.0];
        $aggregates = [];

        $leads = $filters->period($filters->scope($viewer, $this->visibility, Lead::query()), 'leads.created_at')
            ->selectRaw('leads.lead_source_id AS source_id, COUNT(*) AS leads, SUM(CASE WHEN leads.qualified_at IS NOT NULL THEN 1 ELSE 0 END) AS qualified, SUM(CASE WHEN leads.converted_at IS NOT NULL THEN 1 ELSE 0 END) AS converted')
            ->groupBy('leads.lead_source_id')
            ->toBase()
            ->get();

        foreach ($leads as $aggregate) {
            $id = (int) ($aggregate->source_id ?? 0);
            $aggregates[$id] = [
                ...($aggregates[$id] ?? $empty),
                'leads' => (int) $aggregate->leads,
                'qualified' => (int) $aggregate->qualified,
                'converted' => (int) $aggregate->converted,
            ];
        }

        // The deal's own source, else the source of the lead it came from —
        // but the lead is read through the viewer's *lead* scope, as a
        // subquery join: a raw join would read a lead the viewer may not
        // open (and would step over Lead's SoftDeletes scope), so a deal
        // whose lead is out of reach falls through COALESCE to "no source".
        $sourceExpression = 'COALESCE(deals.lead_source_id, leads.lead_source_id)';
        $visibleLeads = $filters->scope($viewer, $this->visibility, Lead::query())->select(['leads.id', 'leads.lead_source_id']);
        $deals = $filters->period($filters->scope($viewer, $this->visibility, Deal::query()), 'deals.won_at')
            ->where('deals.status', DealStatus::Won->value)
            ->leftJoinSub($visibleLeads, 'leads', 'leads.id', '=', 'deals.lead_id')
            ->selectRaw("{$sourceExpression} AS source_id, COUNT(*) AS deals_won, SUM(deals.amount) AS won_amount")
            ->groupByRaw($sourceExpression)
            ->toBase()
            ->get();

        foreach ($deals as $aggregate) {
            $id = (int) ($aggregate->source_id ?? 0);
            $aggregates[$id] = [
                ...($aggregates[$id] ?? $empty),
                'deals_won' => (int) $aggregate->deals_won,
                'won_amount' => round((float) $aggregate->won_amount, 2),
            ];
        }

        $rows = new Collection;

        foreach (LeadSource::query()->orderBy('sort')->orderBy('id')->get() as $source) {
            $id = (int) $source->getKey();

            if (! $source->is_active && ! isset($aggregates[$id])) {
                continue;
            }

            $rows->push($this->row($source->display_name, $aggregates[$id] ?? $empty, $id));
        }

        if (isset($aggregates[0])) {
            $rows->push($this->row(__('reports.labels.no_source'), $aggregates[0], null));
        }

        return $rows;
    }

    /**
     * @param  Collection<int, ReportRow>  $rows
     */
    public function totals(Collection $rows): ReportRow
    {
        $totals = ReportRow::totals(__('reports.totals'), $rows, ['leads', 'qualified', 'converted', 'deals_won', 'won_amount']);

        return new ReportRow($totals->label, [
            ...$totals->values,
            'conversion_rate' => ReportRow::rate($totals->number('converted'), $totals->number('leads')),
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
                ['label' => __('reports.chart.leads'), 'data' => $rows->map(static fn (ReportRow $row): int => (int) $row->value('leads'))->values()->all(), 'color' => 'primary'],
                ['label' => __('reports.chart.converted'), 'data' => $rows->map(static fn (ReportRow $row): int => (int) $row->value('converted'))->values()->all(), 'color' => 'info'],
                ['label' => __('reports.chart.deals_won'), 'data' => $rows->map(static fn (ReportRow $row): int => (int) $row->value('deals_won'))->values()->all(), 'color' => 'success'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'leads' => __('reports.columns.source.leads'),
            'qualified' => __('reports.columns.source.qualified'),
            'converted' => __('reports.columns.source.converted'),
            'deals_won' => __('reports.columns.source.deals_won'),
            'won_amount' => __('reports.columns.source.won_amount'),
            'conversion_rate' => __('reports.columns.source.conversion_rate'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function formats(): array
    {
        return [
            'leads' => ReportRow::FORMAT_COUNT,
            'qualified' => ReportRow::FORMAT_COUNT,
            'converted' => ReportRow::FORMAT_COUNT,
            'deals_won' => ReportRow::FORMAT_COUNT,
            'won_amount' => ReportRow::FORMAT_MONEY,
            'conversion_rate' => ReportRow::FORMAT_PERCENT,
        ];
    }

    /**
     * @param  array<string, int|float>  $values
     */
    private function row(string $label, array $values, ?int $sourceId): ReportRow
    {
        return new ReportRow(
            label: $label,
            values: [...$values, 'conversion_rate' => ReportRow::rate($values['converted'], $values['leads'])],
            meta: ['source_id' => $sourceId],
        );
    }
}
