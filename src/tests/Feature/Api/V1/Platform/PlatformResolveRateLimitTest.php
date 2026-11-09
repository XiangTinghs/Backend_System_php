<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Models\Admin;
use App\Services\PlatformResolveRateLimiter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PlatformJwtFixture;
use Tests\TestCase;

class PlatformResolveRateLimitTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/v1/platform/players/resolve';
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->startOfSecond());
        $this->fixture = PlatformJwtFixture::create();
        config(['platform_api.resolve_per_minute' => 2]);
    }

    private function resolve(string $external = 'Player', ?string $key = null): \Illuminate\Testing\TestResponse
    {
        return $this->withToken(PlatformJwtFixture::token($this->fixture))->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson(self::URL, ['external_user_id' => $external]);
    }

    public function test_existing_default_failover_store_configuration_is_preserved(): void
    {
        $this->assertSame(['database', 'array'], config('cache.stores.failover.stores'));
    }

    public function test_platform_limit_returns_429_with_headers_and_no_business_writes(): void
    {
        $this->resolve('One')->assertCreated()->assertHeader('X-RateLimit-Limit', '2')->assertHeader('X-RateLimit-Remaining', '1');
        $this->resolve('Two')->assertCreated()->assertHeader('X-RateLimit-Remaining', '0');
        $this->resolve('Three')->assertStatus(429)->assertExactJson(['message' => 'Too many requests.'])
            ->assertHeader('Retry-After', '60')->assertHeader('X-RateLimit-Remaining', '0');
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('platform_api_idempotencies', 2);
    }

    public function test_platforms_have_independent_budgets_and_body_cannot_choose_budget(): void
    {
        config(['platform_api.resolve_per_minute' => 1]);
        $first = $this->fixture;
        $other = PlatformJwtFixture::create(1);
        $this->resolve()->assertCreated();
        $this->withToken(PlatformJwtFixture::token($first))->postJson(
            self::URL,
            ['external_user_id' => 'Another player', 'platform_id' => $other['platform']->id]
        )->assertStatus(429);
        $this->fixture = $other;
        $this->resolve()->assertCreated();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('platform_api_idempotencies', 2);
    }

    public function test_per_platform_override_and_window_reset(): void
    {
        config(['platform_api.resolve_platform_limits' => [(string) $this->fixture['platform']->id => 1]]);
        $this->resolve('First')->assertCreated()->assertHeader('X-RateLimit-Limit', '1');
        $this->resolve('Blocked')->assertStatus(429);
        $this->travel(60)->seconds();
        $this->resolve('Next window')->assertCreated();
        $this->assertDatabaseCount('users', 2);
    }

    public function test_replays_and_validation_errors_count_but_invalid_jwt_does_not(): void
    {
        $key = (string) Str::uuid();
        $this->withToken('invalid')->postJson(self::URL, [])->assertUnauthorized();
        $this->resolve(key: $key)->assertCreated();
        $this->resolve(key: $key)->assertCreated();
        $this->resolve(key: $key)->assertStatus(429);
        $this->assertDatabaseCount('users', 1);
        $this->travel(60)->seconds();
        $this->withToken(PlatformJwtFixture::token($this->fixture))->postJson(self::URL, [])->assertUnprocessable();
        $this->resolve('Second')->assertCreated()->assertHeader('X-RateLimit-Remaining', '0');
        $this->resolve('Third')->assertStatus(429);
    }

    public function test_database_storage_failure_returns_503_and_rolls_back_budget(): void
    {
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (str_contains($sql, 'into `platform_api_rate_limits`')) {
                throw new QueryException('mysql', $sql, [], new \PDOException('Simulated cache unavailable'));
            }
        });
        $this->resolve()->assertStatus(503)->assertExactJson(['message' => 'Platform rate limit service unavailable.'])->assertHeader('Retry-After', '1');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('platform_api_idempotencies', 0);
        $this->assertDatabaseCount('platform_api_rate_limits', 0);
    }

    public function test_local_cache_driver_is_rejected_without_fallback(): void
    {
        config(['cache.stores.platform_api_limiter.driver' => 'array']);
        Cache::purge(PlatformResolveRateLimiter::STORE);
        $this->resolve()->assertStatus(503);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('platform_api_idempotencies', 0);
    }

    public function test_invalid_limit_configuration_fails_closed(): void
    {
        config(['platform_api.resolve_per_minute' => 0]);
        $this->resolve()->assertStatus(503);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_default_database_cache_and_platform_budget_are_separate(): void
    {
        config(['platform_api.resolve_per_minute' => 1]);
        Cache::store('database')->put('admin-owned-test-key', 'preserved', 60);
        $this->resolve()->assertCreated();
        $this->resolve()->assertStatus(429);
        $this->assertSame('preserved', Cache::store('database')->get('admin-owned-test-key'));
        Cache::store('database')->forget('admin-owned-test-key');
        $this->resolve()->assertStatus(429);
    }

    public function test_admin_routes_and_default_cache_store_are_not_changed(): void
    {
        config(['platform_api.resolve_per_minute' => 1]);
        $this->resolve()->assertCreated();
        $this->resolve()->assertStatus(429);
        $actor = Admin::factory()->create();
        $token = $actor->createToken('admin-rate-isolation')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/me')->assertOk();
        $this->assertSame('array', config('cache.default'));
        $this->assertSame(1, $actor->tokens()->count());
    }
}
