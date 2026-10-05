<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\WorkspacePanel;
use App\Models\User;
use App\Services\Access\PanelAccess;
use App\Support\Filament\PanelUrl;
use App\Support\Landing\LandingFacts;
use App\Support\Landing\LandingPageChrome;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;

/**
 * The public landing page at the site root (decision D-24). Presentation
 * only: it gathers what the views show and decides nothing.
 *
 * - A guest's call to action is the one sign-in page (the home panel's,
 *   D-23); there is no registration (D-11). A signed-in visitor is offered
 *   the workspace screen instead — only one who may still sign in: an
 *   account suspended while its session lives on would meet a 403 there,
 *   so it is offered the sign-in page like a guest.
 * - The language buttons offer every interface language but the one being
 *   read, each named in its own language, like the profile menu (D-5).
 */
final class LandingController extends Controller
{
    public function __invoke(): View
    {
        $user = auth()->user();
        $signedIn = $user instanceof User && app(PanelAccess::class)->canAccess($user, WorkspacePanel::HOME);
        $current = app()->getLocale();
        $languages = [];

        foreach ((array) config('app.locales') as $locale) {
            if ($locale !== $current) {
                $languages[(string) $locale] = LanguageSwitch::make()->getLabel((string) $locale);
            }
        }

        return view('landing.index', [
            'chrome' => new LandingPageChrome,
            'signedIn' => $signedIn,
            'primaryUrl' => $signedIn
                ? PanelUrl::workspaceScreen()
                : (string) Filament::getPanel(WorkspacePanel::HOME)->getLoginUrl(),
            'facts' => LandingFacts::all(),
            'workspaces' => WorkspacePanel::ordered(),
            'languages' => $languages,
        ]);
    }
}
