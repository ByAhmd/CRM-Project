<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use App\Enums\DealStatus;
use App\Enums\ForecastCategory;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Forecast report (module 23, decision D-8): the viewer's open deals by
 * expected close month and forecast category — amount and weighted amount
 * (amount × effective probability) — for the overdue bucket, the current
 * month and the five that follow, then a "later" and a "no close date"
 * line whenever deals fall there.
 *
 * A snapshot, not a period: it reads the open deals as they are today, so
 * the period filter does not apply; the pipeline comes from the filters,
 * else the default active pipeline. `expected_close_date` is a calendar
 * date, so months are bucketed in SQL without any timezone conversion
 * (grouped through the select alias, which MySQL resolves under
 * ONLY_FULL_GROUP_BY whatever the bound parameters);
 * "today" is the organisation's calendar day (SettingsRepository). Every
 * figure is inside the viewer's deal scope (D-4, D-13).
 */
final class ForecastReport
{
    public const MONTHS_AHEAD = 6;

    public const BUCKET_OVERDUE = 'overdue';

    public const BUCKET_LATER = 'later';

    public const BUCKET_UNSCHEDULED = 'unscheduled';

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

        $today = CarbonImmutable::now($this->settings->timezone())->startOfDay();
        $firstMonth = $today->startOfMonth();
        $afterLastMonth = $firstMonth->addMonths(self::MONTHS_AHEAD);

        $bucketExpression = "CASE WHEN deals.expected_close_date IS NULL THEN ? WHEN deals.expected_close_date < ? THEN ? WHEN deals.expected_close_date >= ? THEN ? ELSE DATE_FORMAT(deals.expected_close_date, '%Y-%m') END";
        $bindings = [self::BUCKET_UNSCHEDULED, $today->toDateString(), self::BUCKET_OVERDUE, $afterLastMonth->toDateString(), self::BUCKET_LATER];

        $buckets = [];

        foreach ($this->query($viewer, $filters, $pipelineId)
            ->join('pipeline_stages', 'pipeline_stages.id', '=', 'deals.stage_id')
            ->selectRaw("{$bucketExpression} AS bucket", $bindings)
            ->selectRaw('deals.forecast_category AS category, SUM(deals.amount) AS amount, SUM(deals.amount * COALESCE(deals.probability, pipeline_stages.probability, 0) / 100) AS weighted')
            ->groupBy('bucket', 'deals.forecast_category')
            ->toBase()
            ->get() as $aggregate) {
            $buckets[(string) $aggregate->bucket][(string) $aggregate->category] = [
                'amount' => round((float) $aggregate->amount, 2),
                'weighted' => round((float) $aggregate->weighted, 2),
            ];
        }

        $keys = [self::BUCKET_OVERDUE];

        for ($offset = 0; $offset < self::MONTHS_AHEAD; $offset++) {
            $keys[] = $firstMonth->addMonths($offset)->format('Y-m');
        }

        foreach ([self::BUCKET_LATER, self::BUCKET_UNSCHEDULED] as $optional) {
            if (isset($buckets[$optional])) {
                $keys[] = $optional;
            }
        }

        foreach ($keys as $key) {
            $values = [];
            $totalAmount = 0.0;
            $totalWeighted = 0.0;

            foreach (ForecastCategory::cases() as $category) {
                $figures = $buckets[$key][$category->value] ?? ['amount' => 0.0, 'weighted' => 0.0];
                $values[$category->value.'_amount'] = $figures['amount'];
                $values[$category->value.'_weighted'] = $figures['weighted'];
                $totalAmount += $figures['amount'];
                $totalWeighted += $figures['weighted'];
            }

            $rows->push(new ReportRow(
                label: self::label($key),
                values: [...$values, 'total_amount' => round($totalAmount, 2), 'total_weighted' => round($totalWeighted, 2)],
                meta: ['bucket' => $key],
            ));
        }

        return $rows;
    }

    /**
     * @param  Collection<int, ReportRow>  $rows
     */
    public function totals(Collection $rows): ReportRow
    {
        return ReportRow::totals(__('reports.totals'), $rows, array_keys($this->columns()));
    }

    /**
     * The weighted amount per bucket, one dataset per forecast category.
     *
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
        $datasets = [];

        foreach (ForecastCategory::cases() as $category) {
            $datasets[] = [
                'label' => $category->getLabel(),
                'data' => $rows->map(static fn (ReportRow $row): float => $row->number($category->value.'_weighted'))->values()->all(),
                'color' => $category->getColor(),
            ];
        }

        return [
            'labels' => $rows->map(static fn (ReportRow $row): string => $row->label)->values()->all(),
            'datasets' => $datasets,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        $columns = [];

        foreach (ForecastCategory::cases() as $category) {
            $columns[$category->value.'_amount'] = __('reports.columns.forecast.'.$category->value.'_amount');
            $columns[$category->value.'_weighted'] = __('reports.columns.forecast.'.$category->value.'_weighted');
        }

        return [
            ...$columns,
            'total_amount' => __('reports.columns.forecast.total_amount'),
            'total_weighted' => __('reports.columns.forecast.total_weighted'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function formats(): array
    {
        return array_map(static fn (): string => ReportRow::FORMAT_MONEY, $this->columns());
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

    /**
     * The bucket as the reader's calendar says it: the three named buckets
     * are translated, a month bucket is its localised month and year — never
     * the raw `2026-09` key, which stays in `meta` for sorting and the export.
     */
    public static function label(string $bucket): string
    {
        return match ($bucket) {
            self::BUCKET_OVERDUE => __('reports.labels.overdue'),
            self::BUCKET_LATER => __('reports.labels.later'),
            self::BUCKET_UNSCHEDULED => __('reports.labels.unscheduled'),
            default => CarbonImmutable::parse($bucket.'-01')->locale(app()->getLocale())->isoFormat('MMMM YYYY'),
        };
    }
}
