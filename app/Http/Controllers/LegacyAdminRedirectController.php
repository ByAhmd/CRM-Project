<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Answers an address that moved out of /admin when the CRM split into
 * workspaces (decision D-23) with a permanent redirect to its new address:
 * the rest of the path and the query string are kept.
 *
 * A signed address — the password-reset link of an invitation sent before
 * the release — is signed for its full URL, so moving it would break the
 * signature. When the old signature is valid, the new address is signed
 * afresh for the same route parameters and the same expiry; an invalid or
 * expired one is passed on unchanged and refused where it lands, as it would
 * have been before.
 */
final class LegacyAdminRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $target = (string) $request->route('target');
        $rest = $request->route('rest');
        $path = '/'.trim($target.'/'.(is_string($rest) ? $rest : ''), '/');
        $query = $request->query();

        if (isset($query['signature']) && $request->hasValidSignature()) {
            $signed = $this->resign($path, $query);

            if ($signed !== null) {
                return redirect()->to($signed, 301);
            }
        }

        $queryString = $request->getQueryString();

        return redirect()->to(url($path).($queryString === null ? '' : '?'.$queryString), 301);
    }

    /**
     * The new address signed like the old one, or null when no named route serves it.
     *
     * @param  array<string, mixed>  $query
     */
    private function resign(string $path, array $query): ?string
    {
        try {
            $route = Route::getRoutes()->match(Request::create($path));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return null;
        }

        $name = $route->getName();

        if ($name === null) {
            return null;
        }

        $expires = $query['expires'] ?? null;
        $parameters = [...$route->parameters(), ...Arr::except($query, ['signature', 'expires'])];

        return is_numeric($expires)
            ? URL::temporarySignedRoute($name, Carbon::createFromTimestamp((int) $expires), $parameters)
            : URL::signedRoute($name, $parameters);
    }
}
