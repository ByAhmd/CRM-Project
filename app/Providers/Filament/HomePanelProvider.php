<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Enums\WorkspacePanel;
use App\Filament\Auth\Pages\RequestPasswordReset;
use App\Filament\Auth\Pages\ResetPassword;
use App\Filament\Pages\Inbox;
use App\Providers\Filament\Concerns\ConfiguresCrmPanel;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * The home panel at /app (decision D-23): not a workspace, open to every
 * user who may sign in (D-11).
 *
 * It owns the whole authentication surface — sign-in, password reset (with
 * the invitation carve-out), the profile page with the optional two-step
 * verification (app + email) — plus notification preferences and the
 * workspace screen, its home page. It is the default panel, so whatever
 * runs outside every panel (the attachment and backup downloads, queued
 * jobs, console commands) resolves to a panel that admits every active
 * user. No registration (invite-only). No sidebar: the screens here are
 * reached from the top bar. Its Inbox serves the workspace screen; inside a
 * workspace the reader reads that workspace's own Inbox, sidebar kept (D-27).
 */
final class HomePanelProvider extends PanelProvider
{
    use ConfiguresCrmPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->configureCrmPanel($panel->default(), WorkspacePanel::HOME)
            ->login()
            ->passwordReset(requestAction: RequestPasswordReset::class, resetAction: ResetPassword::class)
            ->profile(isSimple: false)
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
                EmailAuthentication::make(),
            ])
            ->navigation(false)
            ->globalSearch(false)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
