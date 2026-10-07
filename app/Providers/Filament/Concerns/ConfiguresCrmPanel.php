<?php

declare(strict_types=1);

namespace App\Providers\Filament\Concerns;

use App\Enums\NavigationGroup;
use App\Enums\WorkspacePanel;
use App\Filament\Pages\Calendar;
use App\Filament\Pages\InboxPage;
use App\Filament\Pages\NotificationPreferences;
use App\Http\Middleware\AuthenticateWorkspace;
use App\Http\Middleware\KeepOutOfSearchEngines;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Filament\FilamentLanguageMenuItems;
use App\Support\Filament\PanelUrl;
use App\Support\Filament\WorkspacePanelRegistry;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup as FilamentNavigationGroup;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The configuration every CRM panel shares (decision D-23): the home panel
 * and the four workspaces look, behave and are protected identically, so a
 * setting changed here reaches all five at once and none can drift — a
 * panel silently without the security headers, the notification bell or
 * the motion layer is exactly the failure this trait exists to prevent.
 *
 * What a provider adds on top is only what makes its panel different: the
 * sign-in pages and profile (home), its authentication middleware, global
 * search (Sales and Tasks) and its navigation.
 */
trait ConfiguresCrmPanel
{
    protected function configureCrmPanel(Panel $panel, string $id): Panel
    {
        return $panel
            ->id($id)
            ->path($id)
            ->strictAuthorization()
            ->colors([
                'primary' => Color::hex('#0e7490'),
                'gray' => Color::Slate,
            ])
            ->font('Tajawal')
            ->favicon(asset('favicon.svg'))
            ->viteTheme('resources/css/filament/admin/theme.css')
            // «روح» (A-9 as amended 2026-09-21): the KPI count-up, the one
            // scripted piece of the motion layer; the rest is theme.css.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                static fn (): View => view('filament.motion'),
            )
            // The workspace switcher (D-23), at the start of the top bar's end
            // cluster in every panel: the workspaces the user may enter, then
            // the workspace screen.
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                static fn (): View => view('filament.partials.workspace-switcher'),
            )
            // Install as an app (D-27): the web manifest, the theme colour and
            // the home-screen icon in every panel's head (the landing page
            // shares the home panel's). Invisible tags; no service worker, so
            // nothing private is ever cached on the device.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                static fn (): View => view('filament.partials.app-install'),
            )
            ->darkMode()
            ->defaultThemeMode(ThemeMode::System)
            ->brandName(static fn (): string => (string) __('app.name'))
            // The brand in every top bar leads to the workspace screen (D-23).
            ->homeUrl(static fn (): string => PanelUrl::workspaceScreen())
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->navigationGroups(self::crmNavigationGroups())
            ->userMenuItems([
                // Profile, two-step verification and notification preferences
                // live in the home panel; every panel's user menu leads there.
                'profile' => static fn (Action $action): Action => $action
                    ->label(static fn (): string => EditProfile::getLabel())
                    ->url(static fn (): ?string => Filament::getPanel(WorkspacePanel::HOME)->getProfileUrl()),
                // The Inbox (D-27) of the panel the reader is in: inside a workspace it
                // opens with that workspace's sidebar, never a dead end.
                Action::make('inbox')
                    ->label(static fn (): string => __('inbox.navigation.label'))
                    ->icon(Heroicon::OutlinedInbox)
                    ->url(static fn (): string => InboxPage::urlForCurrentPanel()),
                Action::make('notificationPreferences')
                    ->label(static fn (): string => __('notifications.navigation.label'))
                    ->icon(Heroicon::OutlinedBellAlert)
                    ->url(static fn (): string => NotificationPreferences::getUrl()),
                ...FilamentLanguageMenuItems::userMenuActions(),
            ])
            ->sidebarCollapsibleOnDesktop()
            // SPA mode (A-26): a navigation click swaps the page content
            // instead of reloading the whole panel, and resting the pointer on
            // a link prefetches it — the host is ~100 ms from its users
            // (A-24). Crossing into another panel is always a full load (each
            // panel boots its own navigation, top bar and persisted state),
            // and so is the calendar: its FullCalendar module, a page-level
            // @vite entry, must run before the page's Alpine component starts.
            ->spa(hasPrefetching: true)
            ->spaUrlExceptions(static fn (): array => self::crmSpaUrlExceptions($id))
            ->unsavedChangesAlerts()
            ->resources(WorkspacePanelRegistry::resources($id))
            ->pages(WorkspacePanelRegistry::pages($id))
            ->widgets(WorkspacePanelRegistry::widgets($id))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // Panel routes bypass the `web` group, so the headers are applied here too.
                SecurityHeaders::class,
                // Only the landing page may be indexed (D-24).
                KeepOutOfSearchEngines::class,
            ]);
    }

    /**
     * A workspace panel (D-23): no sign-in page of its own — guests go to the
     * home panel's — and entry decided by PanelAccess through
     * AuthenticateWorkspace, re-run on every Livewire update request.
     */
    protected function configureWorkspacePanel(Panel $panel, WorkspacePanel $workspace): Panel
    {
        return $this->configureCrmPanel($panel, $workspace->id())
            ->login(null)
            ->authMiddleware([AuthenticateWorkspace::class], isPersistent: true);
    }

    /**
     * Every other panel's address space, plus the calendar: links there are
     * full page loads, never in-place swaps.
     *
     * @return list<string>
     */
    private static function crmSpaUrlExceptions(string $currentPanelId): array
    {
        $exceptions = [];

        foreach (WorkspacePanelRegistry::panelIds() as $id) {
            if ($id === $currentPanelId) {
                continue;
            }

            $root = url(Filament::getPanel($id)->getPath());

            array_push($exceptions, $root, $root.'/*', $root.'?*');
        }

        if (WorkspacePanelRegistry::owningPanelId(Calendar::class) === $currentPanelId) {
            $calendar = Calendar::getUrl();

            array_push($exceptions, $calendar, $calendar.'?*');
        }

        return $exceptions;
    }

    /**
     * Groups keyed by enum case name with lazily translated labels, so the
     * sidebar re-translates when the user switches locale (the panel is
     * configured once at boot, in the default locale). Every panel declares
     * every group; Filament only renders the groups a panel's items use.
     *
     * @return array<string, FilamentNavigationGroup>
     */
    private static function crmNavigationGroups(): array
    {
        $groups = [];

        foreach (NavigationGroup::cases() as $case) {
            $groups[$case->name] = FilamentNavigationGroup::make()
                ->label(static fn (): string => $case->getLabel());
        }

        return $groups;
    }
}
