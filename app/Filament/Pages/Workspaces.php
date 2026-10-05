<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\WorkspacePanel;
use App\Filament\Concerns\BuildsPageUrlsInOwningPanel;
use App\Filament\Support\WorkspaceCard;
use App\Models\User;
use App\Services\Access\PanelAccess;
use App\Support\Filament\PanelUrl;
use Filament\Pages\Page;
use Filament\Panel;

/**
 * The workspace screen «لوحة التحكم · اختر مساحة العمل» (decision D-23):
 * the home page of the home panel and where every user lands after signing
 * in. One card per workspace, always all four, in WorkspacePanel order: a
 * workspace the user may enter shows Enter; one they may not is shown locked
 * — lock icon, «غير متاح لحسابك», no link at all — so the screen also tells
 * a user what their account does not reach. Who may enter what is decided by
 * PanelAccess alone.
 */
final class Workspaces extends Page
{
    use BuildsPageUrlsInOwningPanel;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.workspaces';

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public function getTitle(): string
    {
        return __('workspaces.pages.screen.title');
    }

    public function getSubheading(): string
    {
        return __('workspaces.pages.screen.subheading');
    }

    /** Every user who may sign in reaches the screen (the panel itself admits only them). */
    public static function canAccess(): bool
    {
        return auth()->user() instanceof User;
    }

    /**
     * @return list<WorkspaceCard>
     */
    public function getCards(): array
    {
        $user = auth()->user();
        $access = app(PanelAccess::class);

        return array_map(
            static fn (WorkspacePanel $workspace): WorkspaceCard => new WorkspaceCard(
                workspace: $workspace,
                url: $user instanceof User && $access->canAccess($user, $workspace) ? PanelUrl::panelHome($workspace) : null,
            ),
            WorkspacePanel::ordered(),
        );
    }
}
