{{--
    Friendly error page (plan section 7), and the shared template of the other
    error pages: 403, 404, 419, 429 and 503 include this view with their
    status as $crmCode.

    Self-contained on purpose: no Vite build, no session, no database and no
    panel — an error page must render when any of those is what failed. The
    locale is the one the request already runs in (Arabic by default, D-5),
    the direction comes from it, every string comes from lang/errors.php, and
    the colours mirror the --crm-* tokens of the admin theme in both schemes.
    The exception message is never shown: it can be internal and English.
--}}
@php
    $crmCode = isset($crmCode) && in_array((int) $crmCode, [403, 404, 419, 429, 500, 503], true) ? (int) $crmCode : 500;
    $crmDirection = __('filament-panels::layout.direction') === 'rtl' ? 'rtl' : 'ltr';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $crmDirection }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('errors.pages.'.$crmCode.'.title') }} · {{ __('app.name') }}</title>
    <style>
        /* «فيروز» (Fayrouz, A-9 as amended 2026-09-20) — the panel identity,
           copied inline on purpose: an error page must not depend on the
           build that may be exactly what broke. Keep in step with
           resources/css/filament/admin/theme.css. */
        :root {
            --crm-bg: #f2f7f8;
            --crm-card: #ffffff;
            --crm-text: #14262b;
            --crm-muted: #5d737a;
            --crm-border: #dfe9ec;
            --crm-accent: #155e75;
            --crm-on-accent: #ffffff;
            color-scheme: light dark;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --crm-bg: #0d1619;
                --crm-card: #141f23;
                --crm-text: #e4eef1;
                --crm-muted: #8aa2aa;
                --crm-border: #24343a;
                --crm-accent: #0e7490;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-block-size: 100vh;
            display: grid;
            place-items: center;
            padding: 1.5rem;
            background: var(--crm-bg);
            color: var(--crm-text);
            font-family: 'Tajawal', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Tahoma, sans-serif;
            line-height: 1.6;
        }

        main {
            inline-size: 100%;
            max-inline-size: 32rem;
            padding: 2rem;
            border: 1px solid var(--crm-border);
            border-radius: 12px;
            background: var(--crm-card);
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06), 0 6px 20px rgba(15, 23, 42, 0.06);
            text-align: center;
        }

        .code {
            margin: 0;
            color: var(--crm-muted);
            font-size: 0.875rem;
            font-variant-numeric: tabular-nums;
            letter-spacing: 0.04em;
        }

        h1 {
            margin-block: 0.5rem 0.75rem;
            font-size: 1.5rem;
        }

        p {
            margin-block: 0 1.5rem;
            color: var(--crm-muted);
        }

        a {
            display: inline-block;
            padding-block: 0.6rem;
            padding-inline: 1.25rem;
            border-radius: 8px;
            background: var(--crm-accent);
            color: var(--crm-on-accent);
            font-weight: 600;
            text-decoration: none;
        }

        a:focus-visible {
            outline: 2px solid var(--crm-accent);
            outline-offset: 2px;
        }
    </style>
</head>
<body>
    <main>
        <p class="code">{{ __('errors.fields.code', ['code' => $crmCode]) }}</p>
        <h1>{{ __('errors.pages.'.$crmCode.'.title') }}</h1>
        <p>{{ __('errors.pages.'.$crmCode.'.message') }}</p>
        @if ($crmCode !== 503)
            <a href="{{ url('/') }}">{{ __('errors.actions.home') }}</a>
        @endif
    </main>
</body>
</html>
