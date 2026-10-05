<?php

declare(strict_types=1);

namespace App\Filament\Auth\Http\Responses;

use App\Models\User;
use App\Support\Filament\IntendedDestination;
use App\Support\Filament\PanelUrl;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where a user lands after signing in (decision D-23): on the workspace
 * screen — unless they were following a link (an e-mail, a notification, a
 * bookmark, an attachment) to a page they may open, which they then reach.
 *
 * IntendedDestination decides whether the remembered address qualifies:
 * this application's own host only (no open redirect), and a page whose own
 * checks admit the user; anything else — another host, an unknown path, a
 * workspace or a record the user may not open (which would answer 403 or
 * 404) — falls back to the workspace screen. Bound in
 * AppServiceProvider::boot(), after Filament's own binding.
 */
final class LoginResponse implements LoginResponseContract
{
    public function __construct(
        private readonly IntendedDestination $destination,
    ) {}

    public function toResponse($request): RedirectResponse
    {
        /** @var Request $request */
        $intended = session()->pull('url.intended');
        $user = Filament::auth()->user();

        if (is_string($intended) && $user instanceof User) {
            $address = $this->destination->for($user, $intended, $request);

            if ($address !== null) {
                return redirect()->to($address);
            }
        }

        return redirect()->to(PanelUrl::workspaceScreen());
    }
}
