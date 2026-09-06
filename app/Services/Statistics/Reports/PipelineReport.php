<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use App\Enums\DealStatus;
use App\Enums\StageKind;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Pipeline report (module 23, decision D-8): the viewer's open deals of one
 * pipeline by stage — count, amount, weighted amount (amount × the deal's
 * effective probability, D-8) and the average age in days since the deal
 * was created.
 *
 * A snapshot, not a period: the report reads the open deals as they are
 * now, so the period filter does not apply. The pipeline comes from the
 * filters, else the default active pipeline. Every open stage of the
 * pipeline is listed in its order, zero-filled, so the chart keeps the
 * pipeline's shape. Ages are computed in SQL from the current instant of
 * the organisation clock (SettingsRepository), never from the application
 * clock; ReportPagesTest guards that the two agree, which is what lets the
 * naive comparison against the stored created_at hold.
 */
final class PipelineReport
{
    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @return Collection<int, ReportRow>
     */
    public function rows(User $viewer, ReportFilters $filters): Collection
    {
        $pipelineId = $this->pipelineId($filters);
        $rows = new Collection;

        if ($pipelineId === null) {
            return $rows;
        }

        $aggregates = [];

        foreach ($this->query($viewer, $filters, $pipelineId)
            ->join('pipeline_stages', 'pipeline_stages.id', '=', 'deals.stage_id')
            ->selectRaw('deals.stage_id AS stage_id, COUNT(*) AS deals, SUM(deals.amount) AS amount, SUM(deals.amount * COALESCE(deals.probability, pipeline_stages.probability, 0) / 100) AS weighted, AVG(DATEDIFF(?, deals.created_at)) AS age_days', [CarbonImmutable::now($this->settings->timezone())->format('Y-m-d H:i:s')])
            ->groupBy('deals.stage_id')
            ->toBase()
            ->get() as $aggregate) {
            $aggregates[(int) $aggregate->stage_id] = [
                'deals' => (int) $aggregate->deals,
                'amount' => round((float) $aggregate->amount, 2),
                'weighted' => round((float) $aggregate->weighted, 2),
                'age_days' => round((float) $aggregate->age_days, 1),
            ];
        }

        $stages = PipelineStage::query()
            ->where('pipeline_id', $pipelineId)
            ->where('kind', StageKind::Open->value)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        foreach ($stages as $stage) {
            $rows->push(new ReportRow(
                label: $stage->display_name,
                values: $aggregates[(int) $stage->getKey()] ?? ['deals' => 0, 'amount' => 0.0, 'weighted' => 0.0, 'age_days' => 0.0],
                meta: ['stage_id' => (int) $stage->getKey(), 'color' => $stage->color->value, 'probability' => (int) $stage->probability],
            ));
        }

        return $rows;
    }

    /**
     * Sums, with the age averaged over every deal rather than every stage.
     *
     * @param  Collection<int, ReportRow>  $rows
     */
    public function totals(Collection $rows): ReportRow
    {
        $totals = ReportRow::totals(__('reports.totals'), $rows, ['deals', 'amount', 'weighted']);
        $ageSum = $rows->sum(static fn (ReportRow $row): float => $row->number('age_days') * $row->number('deals'));

        return new ReportRow($totals->label, [
            ...$totals->values,
            'age_days' => ReportRow::average($ageSum, $totals->number('deals')),
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
                ['label' => __('reports.chart.amount'), 'data' => $rows->map(static fn (ReportRow $row): float => $row->number('amount'))->values()->all(), 'color' => 'primary'],
                ['label' => __('reports.chart.weighted'), 'data' => $rows->map(static fn (ReportRow $row): float => $row->number('weighted'))->values()->all(), 'color' => 'success'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'deals' => __('reports.columns.pipeline.deals'),
            'amount' => __('reports.columns.pipeline.amount'),
            'weighted' => __('reports.columns.pipeline.weighted'),
            'age_days' => __('reports.columns.pipeline.age_days'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function formats(): array
    {
        return [
            'deals' => ReportRow::FORMAT_COUNT,
            'amount' => ReportRow::FORMAT_MONEY,
            'weighted' => ReportRow::FORMAT_MONEY,
            'age_days' => ReportRow::FORMAT_DECIMAL,
        ];
    }

    /** The pipeline the report reads: the filter's, else the default active pipeline. */
    public function pipelineId(ReportFilters $filters): ?int
    {
        if ($filters->pipelineId !== null) {
            return $filters->pipelineId;
        }

        $id = Pipeline::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('sort')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return Builder<Deal>
     */
    private function query(User $viewer, ReportFilters $filters, int $pipelineId): Builder
    {
        return $filters->scope($viewer, $this->visibility, Deal::query())
            ->where('deals.pipeline_id', $pipelineId)
            ->where('deals.status', DealStatus::Open->value);
    }
}
