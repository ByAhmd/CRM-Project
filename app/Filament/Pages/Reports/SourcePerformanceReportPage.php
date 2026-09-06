<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Models\Lead;
use App\Models\User;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use App\Services\Statistics\Reports\SourcePerformanceReport;
use Illuminate\Support\Collection;

/**
 * Source performance page (module 23): leads and won deals per lead
 * source in the period. Presentation only — SourcePerformanceReport owns
 * the figures.
 */
final class SourcePerformanceReportPage extends BaseReportPage
{
    protected static ?string $slug = 'reports/sources';

    protected static ?int $navigationSort = 60;

    public static function reportKey(): string
    {
        return 'sources';
    }

    protected static function permissionGroup(): string
    {
        return Lead::permissionGroup();
    }

    protected static function columnsKey(): string
    {
        return 'source';
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

    private function report(): SourcePerformanceReport
    {
        return app(SourcePerformanceReport::class);
    }
}
