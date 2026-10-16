<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Models\Task;
use App\Models\User;
use App\Services\Statistics\Reports\EmployeeDashboard;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use App\Services\Statistics\Reports\TaskPerformanceReport;
use Illuminate\Support\Collection;

/**
 * Task performance page (module 23): completed, on-time, late, open and
 * overdue tasks per assignee, with the average time to finish (D-21); a
 * shared task is on the line of each of its assignees (D-28).
 * Presentation only — TaskPerformanceReport owns the figures. Each
 * employee's name opens their employee dashboard (D-26) when the reader may
 * open it for them; the unassigned line and anyone out of reach stay text.
 */
final class TaskPerformanceReportPage extends BaseReportPage
{
    protected static ?string $slug = 'tasks';

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

    protected function rowUrls(User $viewer, Collection $rows): array
    {
        if (! EmployeeDashboardPage::canAccess()) {
            return [];
        }

        $assignees = [];

        foreach ($rows as $index => $row) {
            $id = $row->meta['assignee_id'] ?? null;

            if (is_int($id)) {
                $assignees[$index] = $id;
            }
        }

        if ($assignees === []) {
            return [];
        }

        $reachable = array_map('intval', app(EmployeeDashboard::class)->employees($viewer)
            ->whereKey(array_values($assignees))
            ->pluck('users.id')
            ->all());

        $urls = [];

        foreach ($assignees as $index => $id) {
            if (in_array($id, $reachable, true)) {
                $urls[$index] = EmployeeDashboardPage::getUrl(['employee' => $id]);
            }
        }

        return $urls;
    }

    private function report(): TaskPerformanceReport
    {
        return app(TaskPerformanceReport::class);
    }
}
