<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminTokenAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        // Sanctum validates the bearer token; session authentication has no persisted access token.
        $admin = $request->user('sanctum');

        if (! $admin instanceof Admin || ! $request->bearerToken()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = $admin->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! $token->exists) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
