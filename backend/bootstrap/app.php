<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Auth is purely token-based: /admin/login issues a Sanctum bearer
        // token and the SPA sends it as `Authorization: Bearer`. We deliberately
        // do NOT enable statefulApi() — it would treat requests from the
        // configured frontend domain as cookie/session ("stateful") and enforce
        // CSRF, which the token flow never satisfies (login then fails with 419).

        // This is an API-only backend (no server-rendered "login" route), so
        // unauthenticated requests must never try to redirect to one.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Always render a JSON error response instead of Laravel's default
        // redirect-to-login behavior, since there is no web login route.
        $exceptions->shouldRenderJsonWhen(fn () => true);
    })->create();
