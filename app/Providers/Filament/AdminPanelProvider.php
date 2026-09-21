<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Enums\NavigationGroup;
use App\Filament\Auth\Pages\RequestPasswordReset;
use App\Filament\Auth\Pages\ResetPassword;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Settings\SettingsRepository;
use App\Support\Filament\FilamentLanguageMenuItems;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup as FilamentNavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentTimezone;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The single CRM panel (decision A-2).
 *
 * Authentication surface per D-11: login, password reset (with the invitation
 * carve-out), profile page, optional MFA (app + email). No registration.
 */
final class AdminPanelProvider extends PanelProvider
{
    /**
     * Every Filament date-time column, entry and picker renders (and a picker
     * reads its input) in the organisation timezone from General Settings,
     * the same zone the calendar and the reports use; values stay stored in
     * app.timezone. The closure is resolved on use, so a changed setting takes
     * effect on the next render and nothing touches the database at boot.
     */
    public function boot(): void
    {
        FilamentTimezone::set(static fn (): string => app(SettingsRepository::class)->timezone());
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset(requestAction: RequestPasswordReset::class, resetAction: ResetPassword::class)
            ->profile(isSimple: false)
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
                EmailAuthentication::make(),
            ])
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
            ->darkMode()
            ->defaultThemeMode(ThemeMode::System)
            ->brandName(fn (): string => (string) __('app.name'))
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->navigationGroups(self::navigationGroups())
            ->userMenuItems(FilamentLanguageMenuItems::userMenuActions())
            ->sidebarCollapsibleOnDesktop()
            ->unsavedChangesAlerts()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
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
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * Groups keyed by enum case name with lazily translated labels, so the
     * sidebar re-translates when the user switches locale (the panel is
     * configured once at boot, in the default locale).
     *
     * @return array<string, FilamentNavigationGroup>
     */
    private static function navigationGroups(): array
    {
        $groups = [];

        foreach (NavigationGroup::cases() as $case) {
            $groups[$case->name] = FilamentNavigationGroup::make()
                ->label(fn (): string => $case->getLabel());
        }

        return $groups;
    }
}
