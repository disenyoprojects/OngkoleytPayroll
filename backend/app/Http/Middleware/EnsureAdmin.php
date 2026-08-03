<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Restricts a route to full admins (branch logins get 403). */
class EnsureAdmin {
    public function handle(Request $request, Closure $next): Response {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Admins only.');
        }

        return $next($request);
    }
}
