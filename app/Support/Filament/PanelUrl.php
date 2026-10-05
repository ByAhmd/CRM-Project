<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Enums\WorkspacePanel;
use App\Filament\Pages\Workspaces;
use App\Models\User;
use App\Services\Access\PanelAccess;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * Links between panels (decision D-23).
 *
 * Resources and pages already build their URLs in the panel that owns them
 * (BuildsUrlsInOwningPanel), wherever the link is built; what this class adds
 * is the reader's side: a link into a workspace the reader may not enter
 * would only answer 403, so every cross-workspace link builder asks
 * forReader() and shows nothing instead — links never dead-end.
 */
final class PanelUrl
{
    /** The workspace screen: the home panel's home page, where every signed-in user lands. */
    public static function workspaceScreen(): string
    {
        return Workspaces::getUrl();
    }

    /** The home page of a workspace. */
    public static function panelHome(WorkspacePanel $workspace): string
    {
        return (string) Filament::getPanel($workspace->id())->getUrl();
    }

    /**
     * Whether the reader may enter the panel that owns the resource or page
     * class. A class no panel registers is refused: the link would have
     * nowhere to go.
     */
    public static function readerMayEnter(?User $reader, string $class): bool
    {
        $owner = WorkspacePanelRegistry::owningPanelId($class);

        return $reader instanceof User
            && $owner !== null
            && app(PanelAccess::class)->canAccess($reader, $owner);
    }

    /**
     * The URL (a closure is only evaluated when needed) when the reader may
     * enter the workspace that owns the resource or page class; null
     * otherwise, so the caller renders no link.
     *
     * @param  Closure(): string|string  $url
     */
    public static function forReader(?User $reader, string $class, Closure|string $url): ?string
    {
        if (! self::readerMayEnter($reader, $class)) {
            return null;
        }

        return $url instanceof Closure ? $url() : $url;
    }

    /**
     * The id of the panel a URL of this application lies in (see
     * sameSiteParts() for what "of this application" demands), when the
     * first segment of its path is a panel's path; null for anything else —
     * another host, a path outside every panel, an address that is not
     * plainly this site's — so a caller can never be talked into redirecting
     * off-site or into a path it did not recognise.
     */
    public static function panelIdForUrl(string $url, Request $request): ?string
    {
        $parts = self::sameSiteParts($url, $request);

        if ($parts === null) {
            return null;
        }

        $first = explode('/', trim($parts['path'], '/'))[0];

        foreach (WorkspacePanelRegistry::panelIds() as $id) {
            if ($first === Filament::getPanel($id)->getPath()) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The URL rebuilt on this application's root from its validated path and
     * query (see sameSiteParts()), or null. A caller that redirects uses this
     * address, never the string it was handed: only the parts that were
     * checked reach the browser.
     */
    public static function sameSiteAddress(string $url, Request $request): ?string
    {
        $parts = self::sameSiteParts($url, $request);

        if ($parts === null) {
            return null;
        }

        return url($parts['path']).($parts['query'] === null ? '' : '?'.$parts['query']);
    }

    /**
     * The path and query of a URL that is plainly this application's: an
     * absolute path, or an http(s) address on the request's host (and port,
     * when given), with no dot segments (encoded or not).
     *
     * PHP's parse_url and a browser disagree on some inputs: a browser reads
     * a backslash as a slash in an http(s) address and drops tabs and line
     * breaks, so "http://evil.example\@this-host/" is this host to PHP and
     * evil.example to the browser. An address carrying a backslash, a control
     * or whitespace character, or userinfo (user:password@) is therefore
     * refused instead of interpreted.
     *
     * @return array{path: string, query: ?string}|null
     */
    private static function sameSiteParts(string $url, Request $request): ?array
    {
        if (preg_match('/[\\\\\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['host']) || isset($parts['scheme'])) {
            if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
                return null;
            }

            if (strcasecmp($parts['host'] ?? '', $request->getHost()) !== 0) {
                return null;
            }

            if (isset($parts['port']) && $parts['port'] !== (int) $request->getPort()) {
                return null;
            }
        } elseif (! str_starts_with($url, '/')) {
            return null;
        }

        $path = '/'.ltrim($parts['path'] ?? '', '/');

        foreach (explode('/', trim($path, '/')) as $segment) {
            if (in_array(rawurldecode($segment), ['.', '..'], true)) {
                return null;
            }
        }

        $query = $parts['query'] ?? null;

        return ['path' => $path, 'query' => $query === '' ? null : $query];
    }
}
