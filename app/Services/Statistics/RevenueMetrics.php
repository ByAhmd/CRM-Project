<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The closed-deal numbers of the dashboard (decisions D-4, D-8).
 *
 * Every query starts from the viewer's visibility scope and is narrowed by
 * the filters (owner, team, pipeline). "In the period" means won_at /
 * lost_at between the filter bounds.
 *
 * Monthly buckets: the won deals of the window are selected as rows (a
 * year of wins is a small set) and folded into months in PHP after
 * converting each won_at from the application timezone — what every
 * stored timestamp is in — to the organisation timezone (D-8), so the
 * buckets agree with wonInPeriod() whatever timezone an administrator
 * picks in General Settings. No SQL DATE() or CONVERT_TZ(): DATE() would
 * name the day in the application timezone, and CONVERT_TZ() needs the
 * MySQL timezone tables, which shared hosting (D-1) does not guarantee.
 */
final class RevenueMetrics
{
    public const MONTHS = 12;

    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
    ) {}

    /**
     * @return array{count: int, amount: float}
     */
    public function wonInPeriod(User $viewer, DashboardFilters $filters): array
    {
        return $this->closedInPeriod($viewer, $filters, DealStatus::Won, 'won_at');
    }

    /**
     * @return array{count: int, amount: float}
     */
    public function lostInPeriod(User $viewer, DashboardFilters $filters): array
    {
        return $this->closedInPeriod($viewer, $filters, DealStatus::Lost, 'lost_at');
    }

    /**
     * Won ÷ (won + lost) closed in the period, as a percentage with two
     * decimals; 0 when nothing was closed.
     */
    public function winRate(User $viewer, DashboardFilters $filters): float
    {
        $won = $this->wonInPeriod($viewer, $filters)['count'];
        $lost = $this->lostInPeriod($viewer, $filters)['count'];

        if ($won + $lost === 0) {
            return 0.0;
        }

        return round($won / ($won + $lost) * 100, 2);
    }

    /**
     * The amount won per month over the last N months up to the current
     * one, oldest first, months without a win included at zero.
     *
     * @return list<array{month: string, amount: float}> month as 'Y-m'
     */
    public function revenueWonByMonth(User $viewer, DashboardFilters $filters, int $months = self::MONTHS): array
    {
        $months = max(1, $months);
        $timezone = DashboardFilters::timezone();
        $now = Carbon::now($timezone);
        $first = $now->copy()->startOfMonth()->subMonths($months - 1);

        $buckets = [];

        for ($month = $first->copy(); $month->lessThanOrEqualTo($now); $month->addMonth()) {
            $buckets[$month->format('Y-m')] = 0.0;
        }

        $appTimezone = (string) config('app.timezone');

        $rows = $this->scoped($viewer, $filters)
            ->where('deals.status', DealStatus::Won->value)
            ->whereBetween('deals.won_at', [
                $first->copy()->setTimezone($appTimezone),
                $now->copy()->endOfMonth()->setTimezone($appTimezone),
            ])
            ->select(['deals.won_at', 'deals.amount'])
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $key = Carbon::parse((string) $row->won_at, $appTimezone)->setTimezone($timezone)->format('Y-m');

            if (array_key_exists($key, $buckets)) {
                $buckets[$key] += (float) $row->amount;
            }
        }

        $series = [];

        foreach ($buckets as $month => $amount) {
            $series[] = ['month' => $month, 'amount' => round($amount, 2)];
        }

        return $series;
    }

    /**
     * @return array{count: int, amount: float}
     */
    private function closedInPeriod(User $viewer, DashboardFilters $filters, DealStatus $status, string $closedAtColumn): array
    {
        $row = $this->scoped($viewer, $filters)
            ->where('deals.status', $status->value)
            ->whereBetween('deals.'.$closedAtColumn, [$filters->periodStart(), $filters->periodEnd()])
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(deals.amount), 0) AS amount')
            ->toBase()
            ->first();

        return [
            'count' => (int) ($row->total ?? 0),
            'amount' => round((float) ($row->amount ?? 0), 2),
        ];
    }

    /**
     * The viewer's visible deals, narrowed by the filters (D-4).
     *
     * @return Builder<Deal>
     */
    private function scoped(User $viewer, DashboardFilters $filters): Builder
    {
        return $filters->constrainPipeline($filters->constrainOwner($this->visibility->visible($viewer, Deal::query())));
    }
}
