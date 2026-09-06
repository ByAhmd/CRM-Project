<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Models\Deal;
use App\Models\User;
use App\Services\Statistics\Reports\ForecastReport;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use Illuminate\Support\Collection;

/**
 * Forecast page (module 23): open deals by expected close month and
 * forecast category, as they are today — no period filter. Presentation
 * only — ForecastReport owns the figures.
 */
final class ForecastReportPage extends BaseReportPage
{
    protected static ?string $slug = 'reports/forecast';

    protected static ?int $navigationSort = 80;

    public static function reportKey(): string
    {
        return 'forecast';
    }

    protected static function permissionGroup(): string
    {
        return Deal::permissionGroup();
    }

    protected static function usesPeriod(): bool
    {
        return false;
    }

    protected static function usesPipeline(): bool
    {
        return true;
    }

    /** The report is defined over one pipeline, so the filter is required and pre-filled. */
    protected static function pipelineIsRequired(): bool
    {
        return true;
    }

    protected static function chartIsStacked(): bool
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

    private function report(): ForecastReport
    {
        return app(ForecastReport::class);
    }
}
