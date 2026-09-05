<?php

declare(strict_types=1);

namespace App\Support\Filament;

use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

/**
 * Locale switchers in the profile menu (D-5).
 *
 * Each language names itself, so a reader choosing a language can read the
 * option. Only the languages the user is NOT reading in are shown.
 */
final class FilamentLanguageMenuItems
{
    /**
     * @return array<string, Action>
     */
    public static function userMenuActions(): array
    {
        $items = [];

        foreach ((array) config('app.locales') as $locale) {
            $items["locale_{$locale}"] = Action::make("locale_{$locale}")
                ->label(fn (): string => LanguageSwitch::make()->getLabel($locale))
                ->icon(Heroicon::OutlinedLanguage)
                ->visible(fn (): bool => app()->getLocale() !== $locale)
                ->action(function (Component $livewire) use ($locale): void {
                    LanguageSwitch::switchLocale(locale: $locale);

                    $livewire->redirect(request()->header('Referer', url()->current()));
                });
        }

        return $items;
    }
}
