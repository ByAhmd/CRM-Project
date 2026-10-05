<?php

declare(strict_types=1);

namespace App\Filament\Auth\Http\Responses;

use App\Enums\WorkspacePanel;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;

/**
 * Signing out from any panel leads to the one sign-in page, the home
 * panel's (D-23). Filament's own response would send a workspace — which has
 * no login of its own — back to its home page, and from there through the
 * guest redirect with that page remembered for whoever signs in next.
 */
final class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        return redirect()->to((string) Filament::getPanel(WorkspacePanel::HOME)->getLoginUrl());
    }
}
