<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Models\Deal;
use App\Models\User;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use App\Services\Statistics\Reports\WinLossReport;
use Illuminate\Support\Collection;

/**
 * Win / loss page (module 23): won against lost per month as a line
 * chart, and the close reasons as the table. The pipeline filter is
 * offered but optional — the report is defined over every deal closed in
 * the viewer's scope, and picking a pipeline only narrows it.
 * Presentation only — WinLossReport owns the figures.
 */
final class WinLossReportPage extends BaseReportPage
{
    protected static ?string $slug = 'reports/win-loss';

    protected static ?int $navigationSort = 70;

    public static function reportKey(): string
    {
        return 'win_loss';
    }

    protected static function permissionGroup(): string
    {
        return Deal::permissionGroup();
    }

    protected static function usesPipeline(): bool
    {
        return true;
    }

    protected static function chartType(): string
    {
        return 'line';
    }

    protected function reportRows(User $viewer, ReportFilters $filters): Collection
    {
        return $this->report()->rows($viewer, $filters);
    }

    protected function reportTotals(Collection $rows): ReportRow
    {
        return $this->report()->totals($rows);
    }

    /** The chart is the monthly series, not the close-reason table, so it is its own query. */
    protected function reportChart(User $viewer, ReportFilters $filters, Collection $rows): array
    {
        return $this->report()->chart($viewer, $filters);
    }

    protected function reportColumns(): array
    {
        return $this->report()->columns();
    }

    protected function reportFormats(): array
    {
        return $this->report()->formats();
    }

    private function report(): WinLossReport
    {
        return app(WinLossReport::class);
    }
}
