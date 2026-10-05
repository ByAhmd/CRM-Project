<?php

declare(strict_types=1);

namespace App\Support\Landing;

/**
 * What Filament's base layout (<x-filament-panels::layout.base>) reads from
 * the page it wraps, for the landing page (decision D-24), which is a plain
 * view rather than a Livewire page.
 *
 * The layout asks its `livewire` object for the page title (rendered as
 * «title - brand» in <title>), the render-hook scopes and the extra body
 * attributes; without such an object the page would have no title. The
 * scope is this class, so the landing's own head tags (description, Open
 * Graph, canonical — registered in AppServiceProvider) render here and on no
 * panel page.
 */
final class LandingPageChrome
{
    /**
     * The landing page's own address in a language: the bare root for the
     * default language — what a visitor who has chosen nothing, every
     * crawler included, is served (D-5) — and `?locale=xx` for the others,
     * which the language switch honours for such a visitor. The canonical
     * address, og:url and the hreflang alternates are built from it.
     */
    public static function addressFor(string $locale): string
    {
        return $locale === config('app.default_locale')
            ? route('landing')
            : route('landing', ['locale' => $locale]);
    }

    public function getTitle(): string
    {
        return __('landing.meta.title');
    }

    /**
     * @return list<string>
     */
    public function getRenderHookScopes(): array
    {
        return [self::class];
    }

    /**
     * @return array<string, string>
     */
    public function getExtraBodyAttributes(): array
    {
        return ['class' => 'crm-landing-body'];
    }
}
