<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Enums\BadgeColor;
use App\Enums\LeadStatusKind;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * The lead funnel numbers of the dashboard (decisions D-4, D-7).
 *
 * Every query starts from the viewer's visibility scope and is narrowed by
 * the filters (owner, team); nothing here widens what the viewer may list.
 * "In the period" means the lead was created, qualified or converted
 * between the filter bounds; leadsByStatus() is the open funnel as it
 * stands today, whatever the period. Grouping is done in SQL, with every
 * selected column in GROUP BY (ONLY_FULL_GROUP_BY).
 */
final class LeadFunnelMetrics
{
    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
    ) {}

    /**
     * Leads created in the period, counted by the kind of their current
     * status, plus 'total'. Every kind is present, at zero when empty.
     *
     * @return array<string, int>
     */
    public function countsByStatusKind(User $viewer, DashboardFilters $filters): array
    {
        $counts = array_fill_keys(array_map(static fn (LeadStatusKind $kind): string => $kind->value, LeadStatusKind::cases()), 0);

        $rows = $this->createdInPeriod($viewer, $filters)
            ->join('lead_statuses', 'lead_statuses.id', '=', 'leads.lead_status_id')
            ->selectRaw('lead_statuses.kind AS kind, COUNT(*) AS total')
            ->groupBy('lead_statuses.kind')
            ->toBase()
            ->get();

        $total = 0;

        foreach ($rows as $row) {
            $counts[(string) $row->kind] = (int) $row->total;
            $total += (int) $row->total;
        }

        $counts['total'] = $total;

        return $counts;
    }

    public function newInPeriod(User $viewer, DashboardFilters $filters): int
    {
        return $this->createdInPeriod($viewer, $filters)->count();
    }

    public function qualifiedInPeriod(User $viewer, DashboardFilters $filters): int
    {
        return $this->scoped($viewer, $filters)
            ->whereBetween('leads.qualified_at', [$filters->periodStart(), $filters->periodEnd()])
            ->count();
    }

    public function convertedInPeriod(User $viewer, DashboardFilters $filters): int
    {
        return $this->scoped($viewer, $filters)
            ->whereBetween('leads.converted_at', [$filters->periodStart(), $filters->periodEnd()])
            ->count();
    }

    /**
     * Converted ÷ created in the period, as a percentage with two decimals;
     * 0 when no lead was created.
     */
    public function conversionRate(User $viewer, DashboardFilters $filters): float
    {
        $created = $this->newInPeriod($viewer, $filters);

        if ($created === 0) {
            return 0.0;
        }

        return round($this->convertedInPeriod($viewer, $filters) / $created * 100, 2);
    }

    /**
     * Open leads today (whatever the period), counted per active status of
     * a non-terminal kind, in the statuses' own order. Statuses without a
     * lead are present at zero so the chart keeps the funnel's shape.
     *
     * @return array<string, int> display name => count
     */
    public function leadsByStatus(User $viewer, DashboardFilters $filters): array
    {
        $counts = [];

        foreach ($this->leadsByStatusSeries($viewer, $filters) as $entry) {
            $counts[$entry['label']] = $entry['count'];
        }

        return $counts;
    }

    /**
     * The same open funnel with the status colour, for the chart.
     *
     * @return list<array{label: string, color: BadgeColor, count: int}>
     */
    public function leadsByStatusSeries(User $viewer, DashboardFilters $filters): array
    {
        $statuses = LeadStatus::query()
            ->where('is_active', true)
            ->whereNotIn('kind', self::terminalKinds())
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        if ($statuses->isEmpty()) {
            return [];
        }

        $counts = $this->scoped($viewer, $filters)
            ->whereNull('leads.converted_at')
            ->whereIn('leads.lead_status_id', $statuses->modelKeys())
            ->selectRaw('leads.lead_status_id AS status_id, COUNT(*) AS total')
            ->groupBy('leads.lead_status_id')
            ->toBase()
            ->pluck('total', 'status_id');

        return $statuses
            ->map(static fn (LeadStatus $status): array => [
                'label' => $status->display_name,
                'color' => $status->color,
                'count' => (int) ($counts[$status->getKey()] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private static function terminalKinds(): array
    {
        return array_values(array_map(
            static fn (LeadStatusKind $kind): string => $kind->value,
            array_filter(LeadStatusKind::cases(), static fn (LeadStatusKind $kind): bool => $kind->isTerminal()),
        ));
    }

    /**
     * @return Builder<Lead>
     */
    private function createdInPeriod(User $viewer, DashboardFilters $filters): Builder
    {
        return $this->scoped($viewer, $filters)
            ->whereBetween('leads.created_at', [$filters->periodStart(), $filters->periodEnd()]);
    }

    /**
     * The viewer's visible leads, narrowed by the owner and team filters (D-4).
     *
     * @return Builder<Lead>
     */
    private function scoped(User $viewer, DashboardFilters $filters): Builder
    {
        return $filters->constrainOwner($this->visibility->visible($viewer, Lead::query()));
    }
}
