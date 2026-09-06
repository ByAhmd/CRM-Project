<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Enums\DealStatus;
use App\Enums\StageKind;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The open pipeline numbers of the dashboard (decisions D-4, D-8).
 *
 * Every query starts from the viewer's visibility scope and is narrowed by
 * the filters (owner, team, pipeline). The weighted figures apply the
 * effective probability the Deal model defines — the per-deal override,
 * else the stage probability, clamped to 0..100 — computed in SQL through a
 * join to pipeline_stages so the sum never loads the deals. Grouping is
 * done in SQL with every selected column in GROUP BY (ONLY_FULL_GROUP_BY).
 */
final class PipelineMetrics
{
    /** Days without activity after which an open deal is reported stale. */
    public const STALE_DAYS = 14;

    public const STALE_LIMIT = 10;

    /** amount × effective probability, as the Deal accessor computes it, in SQL (D-8). */
    private const WEIGHTED_SQL = 'deals.amount * LEAST(100, GREATEST(0, COALESCE(deals.probability, pipeline_stages.probability, 0))) / 100';

    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
    ) {}

    public function openDealsCount(User $viewer, DashboardFilters $filters): int
    {
        return $this->openDeals($viewer, $filters)->count();
    }

    public function openDealsAmount(User $viewer, DashboardFilters $filters): float
    {
        return round((float) $this->openDeals($viewer, $filters)->sum('deals.amount'), 2);
    }

    public function weightedPipeline(User $viewer, DashboardFilters $filters): float
    {
        $weighted = $this->openDeals($viewer, $filters)
            ->join('pipeline_stages', 'pipeline_stages.id', '=', 'deals.stage_id')
            ->selectRaw('COALESCE(SUM('.self::WEIGHTED_SQL.'), 0) AS weighted')
            ->toBase()
            ->value('weighted');

        return round((float) $weighted, 2);
    }

    /**
     * The pipeline the stage breakdown covers: the chosen one, else the
     * default active pipeline. Null when the organisation has no active
     * pipeline at all. The chart names it in its heading whenever the
     * filter is empty, because the KPI strip above it spans every
     * pipeline while these bars cover one.
     */
    public function chartedPipeline(DashboardFilters $filters): ?Pipeline
    {
        $id = $filters->pipelineId ?? self::defaultPipelineId();

        return $id === null ? null : Pipeline::query()->find($id);
    }

    /**
     * The open deals per open stage of the chosen pipeline — the default
     * pipeline when none was chosen — in stage order, stages without a
     * deal included at zero.
     *
     * @return list<array{stage_id: int, label: string, count: int, amount: float, weighted: float}>
     */
    public function byStage(User $viewer, DashboardFilters $filters): array
    {
        $pipelineId = $filters->pipelineId ?? self::defaultPipelineId();

        if ($pipelineId === null) {
            return [];
        }

        $stages = PipelineStage::query()
            ->where('pipeline_id', $pipelineId)
            ->where('kind', StageKind::Open->value)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $rows = $this->openDeals($viewer, $filters)
            ->where('deals.pipeline_id', $pipelineId)
            ->join('pipeline_stages', 'pipeline_stages.id', '=', 'deals.stage_id')
            ->selectRaw('deals.stage_id AS stage_id, COUNT(*) AS total, COALESCE(SUM(deals.amount), 0) AS amount, COALESCE(SUM('.self::WEIGHTED_SQL.'), 0) AS weighted')
            ->groupBy('deals.stage_id')
            ->toBase()
            ->get()
            ->keyBy('stage_id');

        return $stages
            ->map(static function (PipelineStage $stage) use ($rows): array {
                $row = $rows->get($stage->getKey());

                return [
                    'stage_id' => (int) $stage->getKey(),
                    'label' => $stage->display_name,
                    'count' => (int) ($row->total ?? 0),
                    'amount' => round((float) ($row->amount ?? 0), 2),
                    'weighted' => round((float) ($row->weighted ?? 0), 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Open deals nobody touched for the given number of days, the longest
     * untouched first: the last activity when one was logged, else the last
     * change to the deal itself.
     *
     * @return Collection<int, Deal>
     */
    public function staleDeals(User $viewer, DashboardFilters $filters, int $days = self::STALE_DAYS, int $limit = self::STALE_LIMIT): Collection
    {
        return $this->staleDealsQuery($viewer, $filters, $days)
            ->with(['account', 'stage', 'owner'])
            ->limit($limit)
            ->get();
    }

    /**
     * The stale deals as a query, ordered oldest first, for the table widget.
     *
     * @return Builder<Deal>
     */
    public function staleDealsQuery(User $viewer, DashboardFilters $filters, int $days = self::STALE_DAYS): Builder
    {
        return $this->openDeals($viewer, $filters)
            ->whereRaw('COALESCE(deals.last_activity_at, deals.updated_at) < ?', [now()->subDays($days)])
            ->orderByRaw('COALESCE(deals.last_activity_at, deals.updated_at) ASC')
            ->orderBy('deals.id');
    }

    /**
     * The viewer's visible open deals, narrowed by the filters (D-4).
     *
     * @return Builder<Deal>
     */
    private function openDeals(User $viewer, DashboardFilters $filters): Builder
    {
        $query = $this->visibility->visible($viewer, Deal::query())
            ->where('deals.status', DealStatus::Open->value);

        return $filters->constrainPipeline($filters->constrainOwner($query));
    }

    /** The default active pipeline, else the first active one. */
    private static function defaultPipelineId(): ?int
    {
        $id = Pipeline::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('sort')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }
}
