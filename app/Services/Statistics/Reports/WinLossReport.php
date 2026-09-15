<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Win / loss report (module 23, decision D-8): the viewer's deals closed
 * in the period — won by won_at, lost by lost_at — as a monthly series of
 * counts and amounts (the chart, monthly()) and as a breakdown by close
 * reason with each reason's share of its outcome (the table, rows()).
 *
 * A reopened deal is open again and appears nowhere. Months are folded in
 * PHP in the organisation timezone (A-19): each closing moment (won_at or
 * lost_at, stored in the application timezone) is placed by comparing it
 * with the first instant of every organisation month of the period — never
 * with SQL DATE(), which would name the day in the application timezone
 * while the organisation timezone is a runtime setting (D-8). The series is
 * zero-filled from the first to the last month of the period. Every figure
 * is inside the viewer's deal scope (D-4, D-13).
 */
final class WinLossReport
{
    /** Closed deals read per batch when the months are folded in PHP. */
    private const BATCH_SIZE = 2000;

    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * The breakdown by close reason: won reasons first, then lost, each
     * outcome by count descending, with the deals lacking a reason last.
     *
     * @return Collection<int, ReportRow>
     */
    public function rows(User $viewer, ReportFilters $filters): Collection
    {
        $aggregates = $this->query($viewer, $filters)
            ->selectRaw('deals.status AS status, deals.close_reason_id AS reason_id, COUNT(*) AS deals, SUM(deals.amount) AS amount')
            ->groupBy('deals.status', 'deals.close_reason_id')
            ->toBase()
            ->get();

        $reasons = DealCloseReason::query()
            ->whereIn('id', $aggregates->pluck('reason_id')->filter()->unique()->all())
            ->get()
            ->keyBy(static fn (DealCloseReason $reason): int => (int) $reason->getKey());

        $rows = new Collection;

        foreach ([DealStatus::Won, DealStatus::Lost] as $status) {
            $outcome = $aggregates->where('status', $status->value);
            $total = (int) $outcome->sum(static fn (object $aggregate): int => (int) $aggregate->deals);

            foreach ($outcome->sortBy(static fn (object $aggregate): array => [-(int) $aggregate->deals, (int) ($aggregate->reason_id ?? PHP_INT_MAX)]) as $aggregate) {
                $reasonId = $aggregate->reason_id === null ? null : (int) $aggregate->reason_id;
                $reason = $reasonId === null ? null : $reasons->get($reasonId);

                $rows->push(new ReportRow(
                    label: $reason === null ? __('reports.labels.no_reason') : $reason->display_name,
                    values: [
                        'kind' => $status->getLabel(),
                        'deals' => (int) $aggregate->deals,
                        'amount' => round((float) $aggregate->amount, 2),
                        'share' => ReportRow::rate((int) $aggregate->deals, $total),
                    ],
                    meta: ['status' => $status->value, 'color' => $status->getColor(), 'reason_id' => $reasonId],
                ));
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, ReportRow>  $rows
     */
    public function totals(Collection $rows): ReportRow
    {
        return ReportRow::totals(__('reports.totals'), $rows, ['deals', 'amount'], ['kind' => '', 'share' => '']);
    }

    /**
     * One line per month of the period: won and lost counts and amounts.
     *
     * @return Collection<int, ReportRow>
     */
    public function monthly(User $viewer, ReportFilters $filters): Collection
    {
        $months = [];
        $keys = [];
        $starts = [];
        $timezone = $this->settings->timezone();
        $appTimezone = (string) config('app.timezone');
        $month = CarbonImmutable::parse($filters->fromDate($timezone), $timezone)->startOfMonth();
        $last = CarbonImmutable::parse($filters->toDate($timezone), $timezone)->startOfMonth();

        while ($month->lessThanOrEqualTo($last)) {
            $months[$month->format('Y-m')] = ['won_count' => 0, 'won_amount' => 0.0, 'lost_count' => 0, 'lost_amount' => 0.0];
            $keys[] = $month->format('Y-m');
            $starts[] = $month->setTimezone($appTimezone)->format('Y-m-d H:i:s');
            $month = $month->addMonth();
        }

        $closed = $this->query($viewer, $filters)
            ->select(['deals.id', 'deals.status', 'deals.won_at', 'deals.lost_at', 'deals.amount'])
            ->toBase()
            ->lazyById(self::BATCH_SIZE, 'deals.id', 'id');

        foreach ($closed as $deal) {
            $won = (string) $deal->status === DealStatus::Won->value;
            $index = self::bucketIndex($starts, (string) ($won ? $deal->won_at : $deal->lost_at));

            if ($index === null) {
                continue;
            }

            $key = $keys[$index];
            $prefix = $won ? 'won' : 'lost';
            $months[$key][$prefix.'_count']++;
            $months[$key][$prefix.'_amount'] = round($months[$key][$prefix.'_amount'] + (float) $deal->amount, 2);
        }

        $rows = new Collection;

        foreach ($months as $key => $values) {
            $rows->push(new ReportRow(self::monthLabel($key), $values, ['month' => $key]));
        }

        return $rows;
    }

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int|float>, color: string}>}
     */
    public function chart(User $viewer, ReportFilters $filters): array
    {
        $months = $this->monthly($viewer, $filters);

        return [
            'labels' => $months->map(static fn (ReportRow $row): string => $row->label)->values()->all(),
            'datasets' => [
                ['label' => __('reports.chart.won'), 'data' => $months->map(static fn (ReportRow $row): int => (int) $row->value('won_count'))->values()->all(), 'color' => 'success'],
                ['label' => __('reports.chart.lost'), 'data' => $months->map(static fn (ReportRow $row): int => (int) $row->value('lost_count'))->values()->all(), 'color' => 'danger'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'kind' => __('reports.columns.win_loss.kind'),
            'deals' => __('reports.columns.win_loss.deals'),
            'amount' => __('reports.columns.win_loss.amount'),
            'share' => __('reports.columns.win_loss.share'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function formats(): array
    {
        return [
            'kind' => ReportRow::FORMAT_TEXT,
            'deals' => ReportRow::FORMAT_COUNT,
            'amount' => ReportRow::FORMAT_MONEY,
            'share' => ReportRow::FORMAT_PERCENT,
        ];
    }

    /**
     * A month bucket as the reader's calendar says it, never the raw ISO
     * key — the key stays in `meta` for sorting and for the export.
     */
    public static function monthLabel(string $key): string
    {
        return CarbonImmutable::parse($key.'-01')
            ->locale(app()->getLocale())
            ->isoFormat('MMMM YYYY');
    }

    /**
     * The bucket a stored moment (application timezone, Y-m-d H:i:s) falls
     * in: the last bucket starting at or before it, found by binary search
     * over the ascending bucket starts; null before the first one.
     *
     * @param  list<string>  $starts
     */
    private static function bucketIndex(array $starts, string $moment): ?int
    {
        $low = 0;
        $high = count($starts) - 1;
        $found = null;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if (strcmp($starts[$middle], $moment) <= 0) {
                $found = $middle;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $found;
    }

    /**
     * The visible deals closed in the period.
     *
     * @return Builder<Deal>
     */
    private function query(User $viewer, ReportFilters $filters): Builder
    {
        return $filters->scope($viewer, $this->visibility, Deal::query())
            ->when($filters->pipelineId !== null, static fn (Builder $query): Builder => $query->where('deals.pipeline_id', $filters->pipelineId))
            ->where(static function (Builder $closed) use ($filters): void {
                $closed
                    ->where(static fn (Builder $won): Builder => $filters->period($won->where('deals.status', DealStatus::Won->value), 'deals.won_at'))
                    ->orWhere(static fn (Builder $lost): Builder => $filters->period($lost->where('deals.status', DealStatus::Lost->value), 'deals.lost_at'));
            });
    }
}
