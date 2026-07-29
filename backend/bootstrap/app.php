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
        // Enables Sanctum's SPA authentication: adds the session/cookie
        // middleware to the api stack so stateful (cookie-based) requests
        // from the configured frontend domains can log in via session,
        // rather than requiring bearer tokens.
        $middleware->statefulApi();

        // This is an API-only backend (no server-rendered "login" route), so
        // unauthenticated requests must never try to redirect to one.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Always render a JSON error response instead of Laravel's default
        // redirect-to-login behavior, since there is no web login route.
        $exceptions->shouldRenderJsonWhen(fn () => true);
    })->create();
