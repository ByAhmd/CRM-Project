@php
    use App\Support\App\WebManifest;
@endphp

{{--
    Install as an app (D-27), in the head of every panel page and of the
    landing page (both boot a panel): the web manifest, the browser chrome's
    colour in each theme, and the home-screen icon iOS reads. No service
    worker is registered anywhere, so nothing private is cached on a device.
--}}
<link rel="manifest" href="{{ route('manifest') }}" />
<meta name="theme-color" media="(prefers-color-scheme: light)" content="{{ WebManifest::THEME_COLOUR }}" />
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="{{ WebManifest::THEME_COLOUR_DARK }}" />
<link rel="apple-touch-icon" href="{{ asset(WebManifest::APPLE_TOUCH_ICON) }}" />
<meta name="mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-title" content="{{ __('app.short_name') }}" />
