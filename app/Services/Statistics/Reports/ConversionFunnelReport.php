<?php

declare(strict_types=1);

namespace App\Services\Statistics\Reports;

use App\Enums\LeadStatusKind;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeadStatusLog;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Conversion funnel (module 23, decision D-7): how many of the viewer's
 * leads entered each stage of the funnel during the period, and the
 * conversion from one step to the next.
 *
 * The stages are the status KINDS — New, Working (contacted), Qualified,
 * Converted — so the funnel stays correct however the administrator names
 * or multiplies the statuses. A stage is "entered" when a
 * lead_status_logs row moves the lead into a status of that kind inside the
 * period; a lead counts once per stage however many times it re-entered.
 * Creation writes no log row, so the New stage also counts the leads
 * created in the period (a lead created New and later moved back to New
 * still counts once).
 *
 * Only logs of the viewer's visible leads are counted (D-4, D-13):
 * the lead query passes through ReportFilters::scope() and the logs are
 * constrained to it.
 */
final class ConversionFunnelReport
{
    /** The funnel stages in order. */
    public const STAGES = [LeadStatusKind::New, LeadStatusKind::Working, LeadStatusKind::Qualified, LeadStatusKind::Converted];

    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
    ) {}

    /**
     * @return Collection<int, ReportRow>
     */
    public function rows(User $viewer, ReportFilters $filters): Collection
    {
        $leads = $filters->scope($viewer, $this->visibility, Lead::query());
        $visibleIds = (clone $leads)->select('leads.id');

        $entered = [];

        foreach ($this->logs($visibleIds, $filters)
            ->join('lead_statuses', 'lead_statuses.id', '=', 'lead_status_logs.to_status_id')
            ->selectRaw('lead_statuses.kind AS kind, COUNT(DISTINCT lead_status_logs.lead_id) AS leads')
            ->groupBy('lead_statuses.kind')
            ->toBase()
            ->get() as $aggregate) {
            $entered[(string) $aggregate->kind] = (int) $aggregate->leads;
        }

        // New: created in the period, or moved (back) into a New status — once per lead.
        $created = $filters->period(clone $leads, 'leads.created_at')->select('leads.id AS lead_id')->toBase();
        $movedToNew = $this->logs($visibleIds, $filters)
            ->whereIn('lead_status_logs.to_status_id', LeadStatus::query()->select('id')->where('kind', LeadStatusKind::New->value))
            ->select('lead_status_logs.lead_id')
            ->toBase();

        $entered[LeadStatusKind::New->value] = (int) DB::query()
            ->fromSub($created->union($movedToNew), 'entries')
            ->distinct()
            ->count('lead_id');

        $rows = new Collection;
        $first = null;
        $previous = null;

        foreach (self::STAGES as $kind) {
            $count = $entered[$kind->value] ?? 0;
            $first ??= $count;

            $rows->push(new ReportRow(
                label: $kind->getLabel(),
                values: [
                    'leads' => $count,
                    'step_rate' => $previous === null ? ($count > 0 ? 100.0 : 0.0) : ReportRow::rate($count, $previous),
                    'overall_rate' => $previous === null ? ($count > 0 ? 100.0 : 0.0) : ReportRow::rate($count, $first),
                ],
                meta: ['kind' => $kind->value, 'color' => $kind->getColor()],
            ));

            $previous = $count;
        }

        return $rows;
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
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'leads' => __('reports.columns.funnel.leads'),
            'step_rate' => __('reports.columns.funnel.step_rate'),
            'overall_rate' => __('reports.columns.funnel.overall_rate'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function formats(): array
    {
        return [
            'leads' => ReportRow::FORMAT_COUNT,
            'step_rate' => ReportRow::FORMAT_PERCENT,
            'overall_rate' => ReportRow::FORMAT_PERCENT,
        ];
    }

    /**
     * The status transitions of the visible leads inside the period.
     *
     * @param  Builder<Lead>  $visibleIds
     * @return Builder<LeadStatusLog>
     */
    private function logs(Builder $visibleIds, ReportFilters $filters): Builder
    {
        return $filters->period(
            LeadStatusLog::query()->whereIn('lead_status_logs.lead_id', $visibleIds),
            'lead_status_logs.changed_at',
        );
    }
}
