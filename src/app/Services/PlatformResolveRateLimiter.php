<?php

namespace App\Services;

use App\Auth\PlatformContext;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PlatformResolveRateLimiter
{
    public const STORE = 'platform_api_limiter';

    public static function key(string $platformId): string
    {
        return 'resolve:v1:platform:' . $platformId;
    }

    public function consume(PlatformContext $context): array
    {
        $limit = config('platform_api.resolve_platform_limits')[$context->platformId]
            ?? config('platform_api.resolve_per_minute');
        if (! is_int($limit) || $limit < 1 || $limit > 1000000) {
            throw new RuntimeException('Invalid platform rate limit configuration.');
        }
        $cache = Cache::store(self::STORE);
        $store = $cache->getStore();
        if (! $store instanceof DatabaseStore || $store->getConnection()->getDriverName() !== 'mysql'
            || $store->getConnection()->getName() !== config('database.default')) {
            throw new RuntimeException('Platform rate limits require the shared application MySQL store.');
        }
        $connection = $store->getConnection();
        $limiter = new RateLimiter($cache);
        $key = self::key($context->platformId);

        return $connection->transaction(function () use ($connection, $limiter, $context, $key, $limit): array {
            // A database row lock has no lease-expiry race. The counter check and hit commit atomically.
            // Laravel DatabaseStore uses this same connection; transaction reads use the writer, not replicas.
            $platform = $connection->table('platforms')->where('id', $context->platformId)->lockForUpdate()->first();
            if ($platform === null || $context->expiresAt <= now('UTC')->getTimestamp()) {
                throw new HttpResponseException(response()->json(['message' => 'Unauthenticated.'], 401));
            }
            if (! $platform->is_active) {
                throw new HttpResponseException(response()->json(['message' => 'Platform is disabled.'], 403));
            }
            if ($limiter->tooManyAttempts($key, $limit)) {
                return ['allowed' => false, 'limit' => $limit, 'remaining' => 0, 'retry_after' => max(1, $limiter->availableIn($key))];
            }
            $hits = $limiter->hit($key, 60);
            // Fail closed even if a cache implementation returns failure rather than throwing.
            if ($hits < 1 || $hits > $limit || $limiter->attempts($key) !== $hits || $limiter->availableIn($key) < 1) {
                throw new RuntimeException('Platform rate accounting did not persist.');
            }
            return ['allowed' => true, 'limit' => $limit, 'remaining' => $limit - $hits, 'retry_after' => 0];
        }, 3);
    }
}
