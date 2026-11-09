<?php

namespace App\Http\Middleware;

use App\Auth\PlatformContext;
use App\Exceptions\PlatformDisabledException;
use App\Services\PlatformJwtVerifier;
use Closure;
use DomainException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

class VerifyPlatformJwt
{
    public function __construct(private readonly PlatformJwtVerifier $verifier)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->remove(PlatformContext::class);
        $authorization = $request->header('Authorization', '');
        if (preg_match('/\ABearer ([A-Za-z0-9_.-]+)\z/i', $authorization, $matches) !== 1) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        try {
            $context = $this->verifier->verify($matches[1]);
        } catch (PlatformDisabledException $exception) {
            return response()->json(['message' => 'Platform is disabled.'], 403);
        } catch (UnexpectedValueException | DomainException | InvalidArgumentException $exception) {
            // Never return/log raw JWT, claims, key material, or library exceptions.
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $request->attributes->set(PlatformContext::class, $context);

        return $next($request);
    }
}
