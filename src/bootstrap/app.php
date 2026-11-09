<?php

use App\Http\Middleware\EnsureAdminHasPermission;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureAdminTokenAuthenticated;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Resolve must reject identity whitespace rather than silently normalizing it.
        $resolve = fn (Request $request) => $request->isMethod('POST') && $request->is('api/v1/platform/players/resolve');
        $middleware->trimStrings(except: [$resolve]);
        $middleware->convertEmptyStringsToNull(except: [$resolve]);
        $middleware->alias([
            'platform.resolve.limit' => \App\Http\Middleware\LimitPlatformResolveRequests::class,
            'platform.jwt' => \App\Http\Middleware\VerifyPlatformJwt::class,
            'admin.token' => EnsureAdminTokenAuthenticated::class,
            'admin.active' => EnsureAdminIsActive::class,
            'admin.permission' => EnsureAdminHasPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
