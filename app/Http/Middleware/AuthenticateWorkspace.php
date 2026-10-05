<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\WorkspacePanel;
use App\Models\User;
use App\Support\Http\LegacyAdminRedirects;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * The authentication middleware of the four workspace panels (D-23).
 *
 * The workspaces have no sign-in page of their own (->login(null)): a guest
 * is sent to the home panel's login, keeping the address they asked for so
 * the login response can bring them back. A signed-in user who may not
 * enter the workspace gets 403 — the workspace screen and the switcher never
 * offer such a link. Two exceptions:
 *
 * - The workspace's sign-out (POST <workspace>/logout) needs only a signed-in
 *   user: someone whose workspace was revoked while its page was open must
 *   still be able to sign out from that page's user menu.
 * - The bare /admin, which until D-23 was the dashboard every user landed on
 *   and which stored notifications and old bookmarks still carry: a user
 *   without the Administration workspace is sent where
 *   LegacyAdminRedirects::formerDashboard() says — the sales dashboard with
 *   the query string kept, or the workspace screen.
 *
 * Registered as Livewire persistent middleware by the panels
 * (authMiddleware(..., isPersistent: true)): Livewire matches persistent
 * middleware by exact class name, so Filament's own registration of its
 * parent class does not cover this one, and without the flag a user whose
 * workspace was revoked mid-session could keep sending update requests from
 * an open page.
 */
final class AuthenticateWorkspace extends Authenticate
{
    /**
     * @param  Request  $request
     * @param  array<string>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();

        if (! $guard->check()) {
            $this->unauthenticated($request, $guards);
        }

        $this->auth->shouldUse(Filament::getAuthGuard());

        $user = $guard->user();
        $panel = Filament::getCurrentOrDefaultPanel();

        if ($user instanceof FilamentUser && $user->canAccessPanel($panel)) {
            return;
        }

        if ($request->routeIs('filament.'.$panel->getId().'.auth.logout')) {
            return;
        }

        if ($user instanceof User && $this->isTheFormerDashboard($request, $panel->getId(), $panel->getPath())) {
            throw new HttpResponseException(redirect()->to(LegacyAdminRedirects::formerDashboard($user, $request->getQueryString())));
        }

        abort(403);
    }

    /**
     * @param  Request  $request
     */
    protected function redirectTo($request): ?string
    {
        return Filament::getPanel(WorkspacePanel::HOME)->getLoginUrl();
    }

    private function isTheFormerDashboard(Request $request, string $panelId, string $panelPath): bool
    {
        return $panelId === WorkspacePanel::Admin->id()
            && $request->isMethod('GET')
            && trim($request->path(), '/') === trim($panelPath, '/');
    }
}
