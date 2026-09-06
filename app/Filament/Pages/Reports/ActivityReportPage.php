<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Models\Activity;
use App\Models\User;
use App\Services\Statistics\Reports\ActivityReport;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use Illuminate\Support\Collection;

/**
 * Activity report page (module 23): activities per user, per day or per
 * week, stacked by kind. Presentation only — ActivityReport owns the
 * figures.
 */
final class ActivityReportPage extends BaseReportPage
{
    protected static ?string $slug = 'reports/activities';

    protected static ?int $navigationSort = 50;

    public static function reportKey(): string
    {
        return 'activities';
    }

    protected static function permissionGroup(): string
    {
        return Activity::permissionGroup();
    }

    protected static function columnsKey(): string
    {
        return 'activity';
    }

    protected static function groupByOptions(): array
    {
        return ActivityReport::GROUP_BY;
    }

    protected static function defaultGroupBy(): string
    {
        return ActivityReport::GROUP_OWNER;
    }

    protected static function chartIsStacked(): bool
    {
        return true;
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

    private function report(): ActivityReport
    {
        return app(ActivityReport::class);
    }
}
