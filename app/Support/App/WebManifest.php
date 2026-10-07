<?php

declare(strict_types=1);

namespace App\Support\App;

use App\Support\Filament\PanelUrl;

/**
 * The web manifest that lets a phone or a desktop install the CRM as an app
 * (decision D-27): its name, the brand icons, the theme colour, and the
 * workspace screen as the start page, opened standalone (no browser bar).
 *
 * It holds nothing private — no user, no record, no setting beyond the
 * product's name — because a browser fetches it without the user's session.
 * There is deliberately no service worker: nothing the CRM shows is ever
 * cached on the device, and the 120-minute session (D-11) still applies
 * inside the installed app.
 */
final class WebManifest
{
    /** The brand teal (A-9, «فيروز»): the panels' primary colour and the light browser chrome. */
    public const string THEME_COLOUR = '#0e7490';

    /** The browser chrome in the dark theme: the dark page ground (theme.css --crm-bg under .dark). */
    public const string THEME_COLOUR_DARK = '#0d1619';

    /** The splash ground while the installed app starts: the light page ground (--crm-bg). */
    public const string BACKGROUND_COLOUR = '#f2f7f8';

    /** The icons under public/icons, by file name: [size, purpose]. */
    public const array ICONS = [
        'icon-192.png' => ['192x192', 'any'],
        'icon-512.png' => ['512x512', 'any'],
        'icon-maskable-512.png' => ['512x512', 'maskable'],
    ];

    /** The home-screen icon iOS reads (it ignores the manifest's icons). */
    public const string APPLE_TOUCH_ICON = 'icons/apple-touch-icon.png';

    /**
     * The manifest in the current locale — always the default one (Arabic,
     * D-5) when served: a browser fetches it without credentials and the
     * route runs without the session, so the reader's language never
     * applies. The short name is the label under the home-screen icon.
     *
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        $name = (string) __('app.name');
        $start = PanelUrl::workspaceScreen();

        return [
            'id' => $start,
            'name' => $name,
            'short_name' => (string) __('app.short_name'),
            'description' => (string) __('landing.meta.description'),
            'lang' => app()->getLocale(),
            'dir' => (string) __('filament-panels::layout.direction') === 'rtl' ? 'rtl' : 'ltr',
            'start_url' => $start,
            'scope' => url('/'),
            'display' => 'standalone',
            'theme_color' => self::THEME_COLOUR,
            'background_color' => self::BACKGROUND_COLOUR,
            'icons' => array_map(
                static fn (string $file, array $icon): array => [
                    'src' => asset('icons/'.$file),
                    'sizes' => $icon[0],
                    'type' => 'image/png',
                    'purpose' => $icon[1],
                ],
                array_keys(self::ICONS),
                array_values(self::ICONS),
            ),
        ];
    }
}
