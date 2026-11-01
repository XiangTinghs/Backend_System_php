<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminHasPermission
{
    // Apply after auth:sanctum, admin.token, and admin.active.
    public function handle(
        Request $request,
        Closure $next,
        ?string $permission = null,
        string ...$additionalPermissions
    ): Response {
        $admin = $request->user('sanctum');

        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Accept exactly one lowercase code, with optional dot-separated segments.
        if (
            $additionalPermissions !== []
            || $permission === null
            || strlen($permission) > 100
            || preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/', $permission) !== 1
            || ! $admin->hasPermission($permission)
        ) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
