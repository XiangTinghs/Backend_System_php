<?php

namespace App\Http\Middleware;

use App\Auth\PlatformContext;
use App\Services\PlatformResolveRateLimiter;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LimitPlatformResolveRequests
{
    public function __construct(private readonly PlatformResolveRateLimiter $limiter)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->attributes->get(PlatformContext::class);
        if (! $context instanceof PlatformContext) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        try {
            $budget = $this->limiter->consume($context);
        } catch (HttpResponseException $exception) {
            return $exception->getResponse();
        } catch (Throwable $exception) {
            // No local fallback or fail-open. Do not log request credentials or raw database errors.
            return response()->json(['message' => 'Platform rate limit service unavailable.'], 503)->header('Retry-After', '1');
        }
        $headers = ['X-RateLimit-Limit' => (string) $budget['limit'], 'X-RateLimit-Remaining' => (string) $budget['remaining']];
        if (! $budget['allowed']) {
            return response()->json(['message' => 'Too many requests.'], 429)
                ->withHeaders($headers + ['Retry-After' => (string) $budget['retry_after']]);
        }
        // Keep downstream errors outside the storage-failure catch. No business operation ran on rejection.
        $response = $next($request);
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }
        return $response;
    }
}
