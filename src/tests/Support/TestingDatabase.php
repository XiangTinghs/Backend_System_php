<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TestingDatabase
{
    public static function verifyConfiguration(array $config): void
    {
        if (($config['driver'] ?? '') !== 'mysql' || ($config['host'] ?? '') !== 'mysql'
            || (string) ($config['port'] ?? '') !== '3306' || ($config['database'] ?? '') !== 'laravel_testing'
            || ! empty($config['url']) || ! empty($config['unix_socket']) || ($config['timezone'] ?? '') !== '+00:00') {
            throw new RuntimeException('Concurrent tests require mysql:3306/laravel_testing with UTC and no DB URL/socket.');
        }
    }

    public static function verify(): void
    {
        if (! app()->environment('testing') || app()->configurationIsCached() || config('database.default') !== 'mysql') {
            throw new RuntimeException('Concurrent tests require uncached testing/MySQL configuration.');
        }
        self::verifyConfiguration(config('database.connections.mysql'));
        $actual = DB::selectOne('SELECT DATABASE() AS db, @@port AS port, @@session.time_zone AS tz');
        if ($actual->db !== 'laravel_testing' || (int) $actual->port !== 3306 || $actual->tz !== '+00:00') {
            throw new RuntimeException('Actual testing database isolation mismatch.');
        }
        $indexes = DB::select('SHOW INDEX FROM platform_jwt_keys');
        if (! collect($indexes)->contains(fn ($index) => $index->Key_name === 'platform_jwt_keys_fingerprint_unique' && (int) $index->Non_unique === 0)) {
            throw new RuntimeException('The global fingerprint unique index must already exist.');
        }
    }

    public static function verifyResolve(): void
    {
        self::verify();
        $rateIndexes = DB::select('SHOW INDEX FROM platform_api_rate_limits');
        if (! collect($rateIndexes)->contains(fn ($index) => $index->Key_name === 'PRIMARY' && (int) $index->Non_unique === 0)) {
            throw new RuntimeException('The dedicated shared rate-limit table must already exist.');
        }
        $indexes = DB::select('SHOW INDEX FROM platform_api_idempotencies');
        if (! collect($indexes)->contains(fn ($index) => $index->Key_name === 'platform_api_idempotencies_scope_unique' && (int) $index->Non_unique === 0)) {
            throw new RuntimeException('The idempotency scope unique index must already exist.');
        }
    }


    public static function clearResolveRateLimit(string $platformId): void
    {
        self::verifyResolve();
        (new \Illuminate\Cache\RateLimiter(\Illuminate\Support\Facades\Cache::store(\App\Services\PlatformResolveRateLimiter::STORE)))
            ->clear(\App\Services\PlatformResolveRateLimiter::key($platformId));
    }

}
