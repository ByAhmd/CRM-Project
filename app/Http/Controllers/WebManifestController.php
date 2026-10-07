<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\App\WebManifest;
use Illuminate\Http\JsonResponse;

/**
 * Serves the web manifest (decision D-27) as application/manifest+json.
 * Public, like the icons it names: it carries the product's name and look
 * only, never anything about a user (WebManifest).
 */
final class WebManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(WebManifest::toArray(), options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
