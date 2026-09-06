<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Models\Task;
use App\Models\User;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use App\Services\Statistics\Reports\TaskPerformanceReport;
use Illuminate\Support\Collection;

/**
 * Task performance page (module 23): completed, on-time, late, open and
 * overdue tasks per assignee. Presentation only — TaskPerformanceReport
 * owns the figures.
 */
final class TaskPerformanceReportPage extends BaseReportPage
{
    protected static ?string $slug = 'reports/tasks';

    protected static ?int $navigationSort = 90;

    public static function reportKey(): string
    {
        return 'tasks';
    }

    protected static function permissionGroup(): string
    {
        return Task::permissionGroup();
    }

    protected static function columnsKey(): string
    {
        return 'task';
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

    private function report(): TaskPerformanceReport
    {
        return app(TaskPerformanceReport::class);
    }
}
