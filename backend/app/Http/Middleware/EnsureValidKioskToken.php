<?php

namespace App\Http\Middleware;

use App\Services\KioskTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureValidKioskToken {
    public function __construct(private KioskTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response {
        $token = $request->bearerToken();
        $employee = $token ? $this->tokens->resolve($token) : null;

        if (! $employee) {
            return response()->json(['message' => 'Invalid or expired kiosk session.'], 401);
        }

        $request->attributes->set('kiosk_employee', $employee);

        return $next($request);
    }
}
