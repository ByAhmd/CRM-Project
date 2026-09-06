<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lead report (module 23, decision D-7): the leads created in the period,
 * grouped by status, source or owner, with how many of them were qualified
 * and converted (whenever that happened) and the conversion rate.
 *
 * Every figure comes from the viewer's visible leads (D-4, D-13) through
 * ReportFilters::scope(). Grouping is done in SQL on the foreign key; the
 * group labels are read afterwards so ONLY_FULL_GROUP_BY never sees a
 * non-aggregated name column. Statuses and sources list every active row
 * (zero-filled) so the chart keeps a stable shape; owners list only those
 * who own a lead in the period, plus an "unassigned" line when needed.
 */
final class LeadReport
{
    public const GROUP_STATUS = 'status';

    public const GROUP_SOURCE = 'source';

    public const GROUP_OWNER = 'owner';

    public const GROUP_BY = [self::GROUP_STATUS, self::GROUP_SOURCE, self::GROUP_OWNER];

    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
    ) {}

    /**
     * @return Collection<int, ReportRow>
     */
    public function rows(User $viewer, ReportFilters $filters): Collection
    {
        $groupBy = $this->groupBy($filters);
        $column = match ($groupBy) {
            self::GROUP_SOURCE => 'leads.lead_source_id',
            self::GROUP_OWNER => 'leads.owner_id',
            default => 'leads.lead_status_id',
        };

        $aggregates = [];

        foreach ($this->query($viewer, $filters)
            ->selectRaw("{$column} AS group_id, COUNT(*) AS leads, SUM(CASE WHEN leads.qualified_at IS NOT NULL THEN 1 ELSE 0 END) AS qualified, SUM(CASE WHEN leads.converted_at IS NOT NULL THEN 1 ELSE 0 END) AS converted")
            ->groupBy($column)
            ->toBase()
            ->get() as $aggregate) {
            $aggregates[(int) ($aggregate->group_id ?? 0)] = [
                'leads' => (int) $aggregate->leads,
                'qualified' => (int) $aggregate->qualified,
                'converted' => (int) $aggregate->converted,
            ];
        }

        $rows = new Collection;

        foreach ($this->groups($groupBy, array_keys($aggregates)) as $id => $label) {
            $values = $aggregates[$id] ?? ['leads' => 0, 'qualified' => 0, 'converted' => 0];

            $rows->push(new ReportRow(
                label: $label,
                values: [...$values, 'conversion_rate' => ReportRow::rate($values['converted'], $values['leads'])],
                meta: ['group' => $groupBy, 'id' => $id === 0 ? null : $id],
            ));
        }

        return $rows;
    }

    /**
     * @param  Collection<int, ReportRow>  $rows
     */
    public function totals(Collection $rows): ReportRow
    {
        $totals = ReportRow::totals(__('reports.totals'), $rows, ['leads', 'qualified', 'converted']);

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
                ['label' => __('reports.chart.qualified'), 'data' => $rows->map(static fn (ReportRow $row): int => (int) $row->value('qualified'))->values()->all(), 'color' => 'info'],
                ['label' => __('reports.chart.converted'), 'data' => $rows->map(static fn (ReportRow $row): int => (int) $row->value('converted'))->values()->all(), 'color' => 'success'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'leads' => __('reports.columns.lead.leads'),
            'qualified' => __('reports.columns.lead.qualified'),
            'converted' => __('reports.columns.lead.converted'),
            'conversion_rate' => __('reports.columns.lead.conversion_rate'),
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
            'conversion_rate' => ReportRow::FORMAT_PERCENT,
        ];
    }

    /** The heading of the label column follows the grouping. */
    public function labelHeading(ReportFilters $filters): string
    {
        return __('reports.filters.options.group_by.'.$this->groupBy($filters));
    }

    public function groupBy(ReportFilters $filters): string
    {
        return in_array($filters->groupBy, self::GROUP_BY, true) ? $filters->groupBy : self::GROUP_STATUS;
    }

    /**
     * @return Builder<Lead>
     */
    private function query(User $viewer, ReportFilters $filters): Builder
    {
        return $filters->period($filters->scope($viewer, $this->visibility, Lead::query()), 'leads.created_at');
    }

    /**
     * The groups in display order: active statuses / sources first (by sort),
     * then the inactive ones that still carry leads, then the "none" line;
     * owners by name.
     *
     * @param  list<int>  $referenced  Group ids with at least one lead (0 = none).
     * @return array<int, string>
     */
    private function groups(string $groupBy, array $referenced): array
    {
        $groups = [];

        if ($groupBy === self::GROUP_OWNER) {
            foreach (User::query()->withTrashed()->whereIn('id', array_filter($referenced))->orderBy('name')->get() as $user) {
                $groups[(int) $user->getKey()] = $user->name;
            }

            if (in_array(0, $referenced, true)) {
                $groups[0] = __('reports.labels.unassigned');
            }

            return $groups;
        }

        $lookups = $groupBy === self::GROUP_SOURCE
            ? LeadSource::query()->orderBy('sort')->orderBy('id')->get()
            : LeadStatus::query()->orderBy('sort')->orderBy('id')->get();

        foreach ($lookups as $lookup) {
            if ($lookup->is_active || in_array((int) $lookup->getKey(), $referenced, true)) {
                $groups[(int) $lookup->getKey()] = $lookup->display_name;
            }
        }

        if ($groupBy === self::GROUP_SOURCE && in_array(0, $referenced, true)) {
            $groups[0] = __('reports.labels.no_source');
        }

        return $groups;
    }
}
