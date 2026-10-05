<?php

declare(strict_types=1);

use App\Http\Middleware\KeepOutOfSearchEngines;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Guests hitting an authenticated route land on the one sign-in page,
        // the home panel's (D-23), not Laravel's default `login` route name
        // (which does not exist here).
        $middleware->redirectGuestsTo(fn (): string => route('filament.app.auth.login'));

        // The trusted proxy list is read from config('crm.trusted_proxies') on every
        // request, so it survives config:cache (the framework middleware would need
        // it fixed at boot through trustProxies(at: ...)).
        $middleware->replace(FrameworkTrustProxies::class, TrustProxies::class);

        $middleware->web(append: [SecurityHeaders::class, KeepOutOfSearchEngines::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
