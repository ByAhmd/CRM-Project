@php
    use Filament\Support\Icons\Heroicon;
@endphp

{{--
    Dark mode on and off, through Filament's own contract: the panel script
    keeps the resolved theme in $store.theme, and a `theme-changed` event
    stores the choice in localStorage "theme" and sets html.dark — exactly
    what the theme switcher in the panels' user menu does, so the choice made
    here is the one the panels open with, and the reverse. It needs
    JavaScript, so it stays hidden (x-cloak) until Alpine runs; without
    JavaScript the page follows the default theme mode.

    Two states on the button, three in what is stored: switching to the
    theme the device itself prefers stores "system" rather than a fixed
    choice, so the default (ThemeMode::System, followed by the panels too)
    comes back after a switch there and back instead of being lost for good.
--}}
<button
    type="button"
    class="crm-landing-tool crm-landing-tool-square"
    data-landing-theme-toggle
    x-data="{
        toggle() {
            const next = this.$store.theme === 'dark' ? 'light' : 'dark'
            const device = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'

            this.$dispatch('theme-changed', next === device ? 'system' : next)
        },
    }"
    x-cloak
    x-on:click="toggle()"
    x-bind:aria-pressed="$store.theme === 'dark' ? 'true' : 'false'"
    aria-pressed="false"
    aria-label="{{ __('landing.nav.theme') }}"
>
    <span class="crm-landing-tool-glyph" x-show="$store.theme !== 'dark'">
        <x-filament::icon :icon="Heroicon::OutlinedMoon" class="crm-landing-tool-icon" />
    </span>

    <span class="crm-landing-tool-glyph" x-show="$store.theme === 'dark'" x-cloak>
        <x-filament::icon :icon="Heroicon::OutlinedSun" class="crm-landing-tool-icon" />
    </span>
</button>
