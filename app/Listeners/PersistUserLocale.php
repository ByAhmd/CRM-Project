<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use BezhanSalleh\LanguageSwitch\Events\LocaleChanged;

/**
 * Remembers the language a signed-in user chose (D-5).
 *
 * The switch stores the choice in a cookie for the browser; this stores it on
 * the account so notifications, mail and a second device follow the same
 * language. Saved quietly: a locale change is a preference, not an audit event.
 */
final class PersistUserLocale
{
    public function handle(LocaleChanged $event): void
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->locale === $event->locale) {
            return;
        }

        if (! in_array($event->locale, (array) config('app.locales'), true)) {
            return;
        }

        $user->forceFill(['locale' => $event->locale])->saveQuietly();
    }
}
