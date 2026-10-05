<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\BuildsPageUrlsInOwningPanel;
use App\Filament\Pages\Reports\BaseReportPage;
use App\Filament\Support\WorkspaceLink;
use App\Filament\Support\WorkspaceLinkSection;
use App\Models\User;
use App\Support\Filament\WorkspacePanelRegistry;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;

/**
 * The home page of the Reports workspace (decision D-23): the reports the
 * viewer may open, each with what it answers. A report the viewer may not
 * open is not listed — every link here opens (ISS-062 in the reference
 * project); the reports themselves keep their own `reports.view` gate.
 */
final class ReportsHome extends Page
{
    use BuildsPageUrlsInOwningPanel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = -2;

    protected string $view = 'filament.pages.workspace-home';

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public static function getNavigationLabel(): string
    {
        return __('workspaces.navigation.reports_home');
    }

    public function getTitle(): string
    {
        return __('workspaces.pages.reports_home.title');
    }

    public function getSubheading(): string
    {
        return __('workspaces.pages.reports_home.subheading');
    }

    /** Whoever entered the workspace sees what it offers them, possibly nothing. */
    public static function canAccess(): bool
    {
        return auth()->user() instanceof User;
    }

    /**
     * @return list<WorkspaceLinkSection>
     */
    public function getSections(): array
    {
        $links = [];

        foreach (WorkspacePanelRegistry::reportPages() as $page) {
            if (! is_subclass_of($page, BaseReportPage::class) || ! $page::canAccess()) {
                continue;
            }

            $links[] = new WorkspaceLink(
                label: $page::getNavigationLabel(),
                url: $page::getUrl(),
                icon: $page::getNavigationIcon(),
                description: __('reports.pages.'.$page::reportKey().'.description'),
            );
        }

        return $links === [] ? [] : [new WorkspaceLinkSection(heading: null, links: $links)];
    }

    public function getEmptyHeading(): string
    {
        return __('workspaces.empty.reports_home.heading');
    }

    public function getEmptyDescription(): string
    {
        return __('workspaces.empty.reports_home.description');
    }
}
