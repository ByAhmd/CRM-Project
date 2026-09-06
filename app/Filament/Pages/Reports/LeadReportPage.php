<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Models\Lead;
use App\Models\User;
use App\Services\Statistics\Reports\LeadReport;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use Illuminate\Support\Collection;

/**
 * Lead report page (module 23): leads created in the period by status,
 * source or owner. Presentation only — LeadReport owns the figures.
 */
final class LeadReportPage extends BaseReportPage
{
    protected static ?string $slug = 'reports/leads';

    protected static ?int $navigationSort = 10;

    public static function reportKey(): string
    {
        return 'leads';
    }

    protected static function permissionGroup(): string
    {
        return Lead::permissionGroup();
    }

    protected static function columnsKey(): string
    {
        return 'lead';
    }

    protected static function groupByOptions(): array
    {
        return LeadReport::GROUP_BY;
    }

    protected static function defaultGroupBy(): string
    {
        return LeadReport::GROUP_STATUS;
    }

    protected function labelHeading(ReportFilters $filters): string
    {
        return $this->report()->labelHeading($filters);
    }

    protected function reportRows(User $viewer, ReportFilters $filters): Collection
    {
        return $this->report()->rows($viewer, $filters);
    }

    protected function reportTotals(Collection $rows): ReportRow
    {
        return $this->report()->totals($rows);
    }

    protected function reportChart(User $viewer, ReportFilters $filters, Collection $rows): array
    {
        return $this->report()->chartFromRows($rows);
    }

    protected function reportColumns(): array
    {
        return $this->report()->columns();
    }

    protected function reportFormats(): array
    {
        return $this->report()->formats();
    }

    private function report(): LeadReport
    {
        return app(LeadReport::class);
    }
}
