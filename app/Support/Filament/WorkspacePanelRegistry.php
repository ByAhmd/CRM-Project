<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Enums\WorkspacePanel;
use App\Filament\Pages\AdminHome;
use App\Filament\Pages\AdminInbox;
use App\Filament\Pages\Calendar;
use App\Filament\Pages\DealBoard;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\NotificationPreferences;
use App\Filament\Pages\Reports\ActivityReportPage;
use App\Filament\Pages\Reports\ConversionFunnelReportPage;
use App\Filament\Pages\Reports\EmployeeDashboardPage;
use App\Filament\Pages\Reports\ForecastReportPage;
use App\Filament\Pages\Reports\LeadReportPage;
use App\Filament\Pages\Reports\PipelineReportPage;
use App\Filament\Pages\Reports\SalesPerformanceReportPage;
use App\Filament\Pages\Reports\SourcePerformanceReportPage;
use App\Filament\Pages\Reports\TaskPerformanceReportPage;
use App\Filament\Pages\Reports\WinLossReportPage;
use App\Filament\Pages\ReportsHome;
use App\Filament\Pages\ReportsInbox;
use App\Filament\Pages\SalesDashboard;
use App\Filament\Pages\SalesInbox;
use App\Filament\Pages\Settings\GeneralSettings;
use App\Filament\Pages\System\Backups;
use App\Filament\Pages\TasksBoard;
use App\Filament\Pages\TasksDashboard;
use App\Filament\Pages\TasksInbox;
use App\Filament\Pages\Workspaces;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\ActivityTypes\ActivityTypeResource;
use App\Filament\Resources\Competitors\CompetitorResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Filament\Resources\DealCloseReasons\DealCloseReasonResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Filament\Resources\Exports\ExportResource;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Industries\IndustryResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\LeadScoringRules\LeadScoringRuleResource;
use App\Filament\Resources\LeadSources\LeadSourceResource;
use App\Filament\Resources\LeadStatuses\LeadStatusResource;
use App\Filament\Resources\Pipelines\PipelineResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\ActivityCountsWidget;
use App\Filament\Widgets\LeadsByStatusChart;
use App\Filament\Widgets\PipelineByStageChart;
use App\Filament\Widgets\RevenueWonByMonthChart;
use App\Filament\Widgets\SalesKpisWidget;
use App\Filament\Widgets\StaleDealsWidget;
use App\Filament\Widgets\UpcomingFollowUpsWidget;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Widgets\Widget;

/**
 * What each panel registers (decision D-23): the resources, pages and
 * widgets of the home panel and of the four workspaces, listed explicitly —
 * nothing is discovered, so a new screen belongs to no workspace until it is
 * placed here, and no screen is ever served by two panels. The panel
 * providers read these lists; owning-panel URL generation
 * (BuildsUrlsInOwningPanel) and the test helpers read owningPanelId().
 *
 * Class namespaces stay where they were before the split, so imports and
 * slugs did not move with the screens.
 */
final class WorkspacePanelRegistry
{
    /**
     * Owner panel id per class, built once from the lists below.
     *
     * @var array<class-string, string>|null
     */
    private static ?array $owners = null;

    /**
     * Every panel id the application registers: the home panel first, then the workspaces in order.
     *
     * @return list<string>
     */
    public static function panelIds(): array
    {
        return [
            WorkspacePanel::HOME,
            ...array_map(static fn (WorkspacePanel $panel): string => $panel->id(), WorkspacePanel::ordered()),
        ];
    }

    /**
     * @return list<class-string<\Filament\Resources\Resource>>
     */
    public static function resources(string $panelId): array
    {
        return match ($panelId) {
            WorkspacePanel::Sales->id() => [
                LeadResource::class,
                DealResource::class,
                ProductResource::class,
                ContactResource::class,
                AccountResource::class,
                ActivityResource::class,
                EmailTemplateResource::class,
                ImportResource::class,
                ExportResource::class,
            ],
            WorkspacePanel::Tasks->id() => [
                TaskResource::class,
                TaskTemplateResource::class,
            ],
            WorkspacePanel::Admin->id() => [
                UserResource::class,
                RoleResource::class,
                TeamResource::class,
                ActivityTypeResource::class,
                CompetitorResource::class,
                DealCloseReasonResource::class,
                IndustryResource::class,
                LeadSourceResource::class,
                LeadStatusResource::class,
                TagResource::class,
                PipelineResource::class,
                CustomFieldResource::class,
                LeadScoringRuleResource::class,
                ActivityLogResource::class,
            ],
            default => [],
        };
    }

    /**
     * The first page of each list is the panel's home (route path '/').
     *
     * @return list<class-string<Page>>
     */
    public static function pages(string $panelId): array
    {
        return match ($panelId) {
            WorkspacePanel::HOME => [
                Workspaces::class,
                NotificationPreferences::class,
                // D-27: the Inbox of the workspace screen; each workspace has its own copy.
                Inbox::class,
            ],
            WorkspacePanel::Sales->id() => [
                SalesDashboard::class,
                DealBoard::class,
                // D-27: each workspace reads the Inbox in place, its sidebar kept (InboxPage).
                SalesInbox::class,
            ],
            WorkspacePanel::Tasks->id() => [
                TasksDashboard::class,
                TasksBoard::class,
                Calendar::class,
                TasksInbox::class,
            ],
            WorkspacePanel::Reports->id() => [
                ReportsHome::class,
                ...self::reportPages(),
                // D-26: the per-employee dashboard, a Reports page beside the nine reports.
                EmployeeDashboardPage::class,
                ReportsInbox::class,
            ],
            WorkspacePanel::Admin->id() => [
                AdminHome::class,
                GeneralSettings::class,
                Backups::class,
                AdminInbox::class,
            ],
            default => [],
        };
    }

    /**
     * @return list<class-string<Widget>>
     */
    public static function widgets(string $panelId): array
    {
        return match ($panelId) {
            WorkspacePanel::Sales->id() => [
                SalesKpisWidget::class,
                LeadsByStatusChart::class,
                PipelineByStageChart::class,
                RevenueWonByMonthChart::class,
                StaleDealsWidget::class,
                ActivityCountsWidget::class,
            ],
            WorkspacePanel::Tasks->id() => [
                // D-27: My Work (TasksDashboard) lists the reader's own tasks itself.
                UpcomingFollowUpsWidget::class,
            ],
            default => [],
        };
    }

    /**
     * The nine report pages, in the reports workspace's order.
     *
     * @return list<class-string<Page>>
     */
    public static function reportPages(): array
    {
        return [
            LeadReportPage::class,
            ConversionFunnelReportPage::class,
            PipelineReportPage::class,
            SalesPerformanceReportPage::class,
            ActivityReportPage::class,
            SourcePerformanceReportPage::class,
            WinLossReportPage::class,
            ForecastReportPage::class,
            TaskPerformanceReportPage::class,
        ];
    }

    /** The panel that registers the resource, page or widget; null for anything no panel lists. */
    public static function owningPanelId(string $class): ?string
    {
        return self::owners()[$class] ?? null;
    }

    /**
     * @return array<class-string, string>
     */
    private static function owners(): array
    {
        if (self::$owners !== null) {
            return self::$owners;
        }

        $owners = [];

        foreach (self::panelIds() as $panelId) {
            foreach ([...self::resources($panelId), ...self::pages($panelId), ...self::widgets($panelId)] as $class) {
                $owners[$class] = $panelId;
            }
        }

        return self::$owners = $owners;
    }
}
