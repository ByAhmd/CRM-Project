@php
    use Filament\Support\Icons\Heroicon;
@endphp

{{--
    One button per interface language not being read, named in its own
    language (D-5). A plain form post (LandingLanguageController), so it
    works without JavaScript; the choice lands where the profile menu's
    switch puts it.
--}}
@foreach ($languages as $locale => $label)
    <form method="post" action="{{ route('landing.language') }}" class="crm-landing-language" data-landing-language="{{ $locale }}">
        @csrf
        <input type="hidden" name="locale" value="{{ $locale }}" />

        <button type="submit" class="crm-landing-tool" aria-label="{{ __('landing.nav.language', ['language' => $label]) }}">
            <x-filament::icon :icon="Heroicon::OutlinedLanguage" class="crm-landing-tool-icon" />
            <span lang="{{ $locale }}">{{ $label }}</span>
        </button>
    </form>
@endforeach
