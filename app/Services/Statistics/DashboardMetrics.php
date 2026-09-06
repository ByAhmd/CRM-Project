<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Models\User;

/**
 * The sales KPI strip of the dashboard (decisions D-4, D-7, D-8): one call
 * that gathers the lead funnel, the open pipeline and the closed deals for
 * the viewer and the filters, so the widget presents and never computes.
 * Every figure comes from the dedicated metrics service and therefore from
 * the viewer's visibility scope.
 */
final class DashboardMetrics
{
    public function __construct(
        private readonly LeadFunnelMetrics $leads,
        private readonly PipelineMetrics $pipeline,
        private readonly RevenueMetrics $revenue,
    ) {}

    /**
     * @return array{
     *     new_leads: int,
     *     qualified: int,
     *     converted: int,
     *     conversion_rate: float,
     *     open_deals_count: int,
     *     open_deals_amount: float,
     *     weighted_pipeline: float,
     *     won_count: int,
     *     won_amount: float,
     *     lost_count: int,
     *     lost_amount: float,
     *     win_rate: float,
     *     revenue_by_month: list<float>
     * }
     */
    public function salesKpis(User $viewer, DashboardFilters $filters): array
    {
        $won = $this->revenue->wonInPeriod($viewer, $filters);
        $lost = $this->revenue->lostInPeriod($viewer, $filters);

        return [
            'new_leads' => $this->leads->newInPeriod($viewer, $filters),
            'qualified' => $this->leads->qualifiedInPeriod($viewer, $filters),
            'converted' => $this->leads->convertedInPeriod($viewer, $filters),
            'conversion_rate' => $this->leads->conversionRate($viewer, $filters),
            'open_deals_count' => $this->pipeline->openDealsCount($viewer, $filters),
            'open_deals_amount' => $this->pipeline->openDealsAmount($viewer, $filters),
            'weighted_pipeline' => $this->pipeline->weightedPipeline($viewer, $filters),
            'won_count' => $won['count'],
            'won_amount' => $won['amount'],
            'lost_count' => $lost['count'],
            'lost_amount' => $lost['amount'],
            'win_rate' => $this->revenue->winRate($viewer, $filters),
            'revenue_by_month' => array_map(
                static fn (array $bucket): float => $bucket['amount'],
                $this->revenue->revenueWonByMonth($viewer, $filters),
            ),
        ];
    }
}
