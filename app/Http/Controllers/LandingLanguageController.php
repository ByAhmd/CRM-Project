<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The landing page's language buttons (decisions D-5, D-24): a plain form
 * post, so switching works without JavaScript. The choice is stored exactly
 * as the profile menu's switch stores it — the language-switch plugin's
 * session value and cookie, and, through its LocaleChanged event, the
 * account of a signed-in reader (PersistUserLocale) — then the page is shown
 * again in the chosen language. Only an interface language is accepted.
 */
final class LandingLanguageController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var array{locale: string} $validated */
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in((array) config('app.locales'))],
        ]);

        LanguageSwitch::switchLocale(locale: $validated['locale']);

        return redirect()->route('landing');
    }
}
