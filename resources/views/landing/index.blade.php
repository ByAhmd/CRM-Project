{{--
    The public landing page (decision D-24) at the site root.

    It is not a panel page: Filament's base layout gives it the home panel's
    theme, Tajawal, the dark-mode boot (localStorage "theme" + html.dark) and
    <html lang/dir> in the reader's language; $chrome supplies the title and
    the render-hook scope of its head tags. One partial per section. Styles
    live in the shared theme (resources/css/filament/admin/theme.css,
    "Landing page"): tokens, logical properties, motion only behind
    prefers-reduced-motion: no-preference — and everything is visible at rest,
    without JavaScript and without motion.

    The base layout brings the panel's script bundle (Alpine, Livewire,
    Filament's scripts) and a session — accepted (D-24 review notes): the
    theme switch runs on Alpine and the language form is CSRF-protected,
    and the page makes no database query.
--}}
<x-filament-panels::layout.base :livewire="$chrome">
    <a href="#crm-landing-main" class="crm-landing-skip">{{ __('landing.nav.skip') }}</a>

    <div class="crm-landing" id="crm-landing-top">
        @include('landing.partials.nav')

        <main id="crm-landing-main" class="crm-landing-main" tabindex="-1">
            @include('landing.partials.hero')
            @include('landing.partials.facts')
            @include('landing.partials.capabilities')
            @include('landing.partials.workspaces')
            @include('landing.partials.faq')
            @include('landing.partials.cta')
        </main>

        @include('landing.partials.footer')
    </div>
</x-filament-panels::layout.base>
