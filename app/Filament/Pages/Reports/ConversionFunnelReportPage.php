<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Models\Lead;
use App\Models\User;
use App\Services\Statistics\Reports\ConversionFunnelReport;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use Illuminate\Support\Collection;

/**
 * Conversion funnel page (module 23): leads entering each funnel stage in
 * the period. Presentation only — ConversionFunnelReport owns the figures;
 * the stages are not summed, so there is no totals line.
 */
final class ConversionFunnelReportPage extends BaseReportPage
{
    protected static ?string $slug = 'reports/funnel';

    protected static ?int $navigationSort = 20;

    public static function reportKey(): string
    {
        return 'funnel';
    }

    protected static function permissionGroup(): string
    {
        return Lead::permissionGroup();
    }

    protected function reportRows(User $viewer, ReportFilters $filters): Collection
    {
        return $this->report()->rows($viewer, $filters);
    }

    protected function reportTotals(Collection $rows): ?ReportRow
    {
        return null;
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

    private function report(): ConversionFunnelReport
    {
        return app(ConversionFunnelReport::class);
    }
}
