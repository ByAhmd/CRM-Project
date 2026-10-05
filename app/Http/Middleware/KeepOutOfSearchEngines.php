<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps everything but the public landing page out of search engines
 * (decision D-24): every response of the panels and of the application's
 * other routes carries `X-Robots-Tag: noindex, nofollow`; the landing page
 * (route `landing`) alone may be indexed.
 *
 * The header is the guard, not robots.txt: a crawler that is forbidden to
 * fetch an address can still list it from the links pointing at it (every
 * landing call to action links to the sign-in page), and never sees a
 * noindex it is not allowed to fetch. robots.txt therefore lets crawlers
 * in, and this header tells them to leave.
 *
 * Applied to the `web` group and to every panel's middleware
 * (ConfiguresCrmPanel), since panel routes bypass the `web` group.
 */
final class KeepOutOfSearchEngines
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->routeIs('landing')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
