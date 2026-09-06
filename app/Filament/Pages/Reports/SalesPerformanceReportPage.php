<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Models\Deal;
use App\Models\User;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use App\Services\Statistics\Reports\SalesPerformanceReport;
use Illuminate\Support\Collection;

/**
 * Sales performance page (module 23): won and lost deals per owner in the
 * period. The pipeline filter is offered but optional — the report is
 * defined over every deal in the viewer's scope, and picking a pipeline
 * only narrows it. Presentation only — SalesPerformanceReport owns the
 * figures.
 */
final class SalesPerformanceReportPage extends BaseReportPage
{
    protected static ?string $slug = 'reports/sales';

    protected static ?int $navigationSort = 40;

    public static function reportKey(): string
    {
        return 'sales';
    }

    protected static function permissionGroup(): string
    {
        return Deal::permissionGroup();
    }

    protected static function usesPipeline(): bool
    {
        return true;
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

    private function report(): SalesPerformanceReport
    {
        return app(SalesPerformanceReport::class);
    }
}
