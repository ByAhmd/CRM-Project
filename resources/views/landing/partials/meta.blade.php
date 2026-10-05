{{--
    The landing page's head tags (D-24), rendered at HEAD_END through a
    render hook scoped to LandingPageChrome: the meta description, the
    canonical address and the Open Graph card in the reader's language, with
    the other interface languages announced as alternates. Each language has
    an address of its own (LandingPageChrome::addressFor(): the root for
    Arabic, ?locale=en for English), so each page is canonical to itself and
    the hreflang alternates let search engines index both. <title>, lang and
    dir come from Filament's base layout.
--}}
@php
    use App\Support\Landing\LandingPageChrome;

    $title = __('landing.meta.title');
    $description = __('landing.meta.description');
    $address = LandingPageChrome::addressFor(app()->getLocale());
@endphp

<meta name="description" content="{{ $description }}" />
<link rel="canonical" href="{{ $address }}" />
@foreach ((array) config('app.locales') as $locale)
    <link rel="alternate" hreflang="{{ $locale }}" href="{{ LandingPageChrome::addressFor((string) $locale) }}" />
@endforeach
<link rel="alternate" hreflang="x-default" href="{{ route('landing') }}" />
<meta property="og:type" content="website" />
<meta property="og:site_name" content="{{ __('app.name') }}" />
<meta property="og:title" content="{{ $title }}" />
<meta property="og:description" content="{{ $description }}" />
<meta property="og:url" content="{{ $address }}" />
<meta property="og:locale" content="{{ __('landing.meta.og_locale') }}" />
@foreach ((array) config('app.locales') as $locale)
    @if ($locale !== app()->getLocale())
        <meta property="og:locale:alternate" content="{{ __('landing.meta.og_locale', [], $locale) }}" />
    @endif
@endforeach
<meta name="twitter:card" content="summary" />
<meta name="twitter:title" content="{{ $title }}" />
<meta name="twitter:description" content="{{ $description }}" />
