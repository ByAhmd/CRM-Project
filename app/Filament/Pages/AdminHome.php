<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\NavigationGroup;
use App\Enums\WorkspacePanel;
use App\Filament\Concerns\BuildsPageUrlsInOwningPanel;
use App\Filament\Support\WorkspaceLink;
use App\Filament\Support\WorkspaceLinkSection;
use App\Models\User;
use App\Support\Filament\WorkspacePanelRegistry;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;

/**
 * The home page of the Administration workspace (decision D-23): every
 * administration screen the viewer may open, grouped as the sidebar groups
 * them (settings, then system). Each resource is checked with its policy's
 * viewAny and each page with its own canAccess() before it is listed, so a
 * link here never answers 403 (ISS-062 in the reference project).
 */
final class AdminHome extends Page
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
        return __('workspaces.navigation.admin_home');
    }

    public function getTitle(): string
    {
        return __('workspaces.pages.admin_home.title');
    }

    public function getSubheading(): string
    {
        return __('workspaces.pages.admin_home.subheading');
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
        /** @var array<string, list<array{sort: int, link: WorkspaceLink}>> $grouped */
        $grouped = [];
        $panelId = WorkspacePanel::Admin->id();

        foreach (WorkspacePanelRegistry::resources($panelId) as $resource) {
            if (! $resource::canViewAny()) {
                continue;
            }

            $grouped[self::groupKey($resource::getNavigationGroup())][] = [
                'sort' => $resource::getNavigationSort() ?? 0,
                'link' => new WorkspaceLink(
                    label: $resource::getNavigationLabel(),
                    url: $resource::getUrl(),
                    icon: $resource::getNavigationIcon(),
                ),
            ];
        }

        foreach (WorkspacePanelRegistry::pages($panelId) as $page) {
            if ($page === self::class || ! $page::canAccess()) {
                continue;
            }

            $grouped[self::groupKey($page::getNavigationGroup())][] = [
                'sort' => $page::getNavigationSort() ?? 0,
                'link' => new WorkspaceLink(
                    label: $page::getNavigationLabel(),
                    url: $page::getUrl(),
                    icon: $page::getNavigationIcon(),
                ),
            ];
        }

        $sections = [];

        foreach ([...NavigationGroup::cases(), null] as $group) {
            $entries = $grouped[self::groupKey($group)] ?? [];

            if ($entries === []) {
                continue;
            }

            usort($entries, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

            $sections[] = new WorkspaceLinkSection(
                heading: $group?->getLabel(),
                links: array_map(static fn (array $entry): WorkspaceLink => $entry['link'], $entries),
            );
        }

        return $sections;
    }

    public function getEmptyHeading(): string
    {
        return __('workspaces.empty.admin_home.heading');
    }

    public function getEmptyDescription(): string
    {
        return __('workspaces.empty.admin_home.description');
    }

    private static function groupKey(mixed $group): string
    {
        return $group instanceof NavigationGroup ? $group->value : '';
    }
}
