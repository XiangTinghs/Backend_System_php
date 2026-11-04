<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditLogIndexTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/v1/admin/audit-logs';

    private function authenticate(bool $permission = true, bool $active = true): Admin
    {
        $actor = Admin::factory()->create(['is_active' => $active]);
        if ($permission) {
            $role = 'audit_reader_' . $actor->id;
            DB::table('admin_roles')->insert(['admin_id' => $actor->id, 'role' => $role]);
            DB::table('roles_permissions')->insert(['role' => $role, 'permission' => 'audit-logs.view']);
        }
        $this->withToken($actor->createToken('audit-reader')->plainTextToken);

        return $actor;
    }

    private function log(array $overrides = []): int
    {
        return DB::table('audit_logs')->insertGetId(array_merge([
            'admin_id' => null, 'action' => 'admin.updated', 'target_type' => 'admin',
            'target_id' => null, 'created_at' => '2026-11-01 12:00:00',
        ], $overrides));
    }

    public function test_authorized_empty_list_has_resource_pagination(): void
    {
        $this->authenticate();
        $this->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.per_page', 20)->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_filters_individually_and_as_and_combination(): void
    {
        $this->authenticate();
        $match = $this->log(['admin_id' => 9876, 'target_id' => 8765, 'action' => 'test.action', 'target_type' => 'test_target']);
        $this->log();
        $filters = ['admin_id' => '9876', 'target_id' => '8765', 'action' => 'test.action', 'target_type' => 'test_target'];
        foreach (array_merge(array_map(fn ($k, $v) => [$k => $v], array_keys($filters), $filters), [$filters]) as $query) {
            app('auth')->forgetGuards();
            $response = $this->getJson(self::URL . '?' . http_build_query($query))->assertOk();
            $this->assertSame([$match], array_column($response->json('data'), 'id'));
        }
        app('auth')->forgetGuards();
        $this->getJson(self::URL . '?admin_id=9876&target_id=123')->assertOk()->assertJsonCount(0, 'data');
        app('auth')->forgetGuards();
        $this->getJson(self::URL . '?action=test')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_utc_date_boundaries_are_inclusive_start_exclusive_next_day(): void
    {
        $this->authenticate();
        $this->log(['created_at' => '2026-10-31 23:59:59']);
        $start = $this->log(['created_at' => '2026-11-01 00:00:00']);
        $end = $this->log(['created_at' => '2026-11-01 23:59:59']);
        $this->log(['created_at' => '2026-11-02 00:00:00']);
        $response = $this->getJson(self::URL . '?date_from=2026-11-01&date_to=2026-11-01')->assertOk();
        $this->assertSame([$end, $start], array_column($response->json('data'), 'id'));
        $this->assertSame('2026-11-01T23:59:59.000000Z', $response->json('data.0.created_at'));
    }

    public function test_pagination_and_stable_order(): void
    {
        $this->authenticate();
        $ids = [];
        for ($i = 0; $i < 23; $i++) {
            $ids[] = $this->log();
        }
        rsort($ids);
        $response = $this->getJson(self::URL)->assertOk()->assertJsonCount(20, 'data');
        $this->assertSame(array_slice($ids, 0, 20), array_column($response->json('data'), 'id'));
        app('auth')->forgetGuards();
        $response = $this->getJson(self::URL . '?page=2')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(array_slice($ids, 20), array_column($response->json('data'), 'id'));
        app('auth')->forgetGuards();
        $this->getJson(self::URL . '?per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_historical_json_is_filtered_and_get_does_not_write(): void
    {
        $this->authenticate();
        $id = $this->log(['old_values' => json_encode(['name' => 'Safe', 'password' => 'sensitive', 'authorization' => 'sensitive',
            'token' => 'sensitive', 'secret' => 'sensitive', 'unknown' => 'sensitive']),
            'new_values' => json_encode(['username' => ['secret' => 'sensitive'], 'is_active' => 'sensitive',
                'roles' => [['token' => 'sensitive']], 'role' => 'viewer'])]);
        $before = DB::table('audit_logs')->orderBy('id')->get()->toArray();
        $response = $this->getJson(self::URL)->assertOk();
        $response->assertJsonPath('data.0.old_values', ['name' => 'Safe'])->assertJsonPath('data.0.new_values', ['role' => 'viewer']);
        $this->assertSame(['id', 'admin_id', 'action', 'target_type', 'target_id', 'old_values', 'new_values', 'ip_address', 'created_at'], array_keys($response->json('data.0')));
        $this->assertStringNotContainsString('sensitive', $response->getContent());
        $this->assertNull($response->json('data.0.admin_id'));
        $this->assertEquals($before, DB::table('audit_logs')->orderBy('id')->get()->toArray());
    }

    public function test_platform_code_snapshot_filters_unexpected_types_and_secrets(): void
    {
        $this->authenticate();
        foreach (['PlatformA', null, ['secret' => 'hidden'], 123, true] as $code) {
            $id = $this->log(['target_type' => 'platform', 'action' => 'platform.created',
                'new_values' => json_encode(['code' => $code, 'secret' => 'hidden'])]);
            $response = $this->getJson(self::URL . '?target_type=platform')->assertOk();
            $response->assertJsonPath('data.0.id', $id);
            $this->assertSame(is_string($code) || $code === null ? ['code' => $code] : [], $response->json('data.0.new_values'));
            $this->assertStringNotContainsString('hidden', $response->getContent());
        }
    }

    public function test_null_snapshots_and_max_unsigned_id_filters_are_supported(): void
    {
        $this->authenticate();
        $this->log(['target_id' => '18446744073709551615']);
        $this->getJson(self::URL . '?target_id=18446744073709551615')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.old_values', null)->assertJsonPath('data.0.new_values', null);
    }

    #[DataProvider('invalidParameters')]
    public function test_invalid_query_returns_422(string $key, mixed $value): void
    {
        $this->authenticate();
        $this->getJson(self::URL . '?' . http_build_query([$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
    }

    public static function invalidParameters(): array
    {
        return [['admin_id', 0], ['admin_id', '-1'], ['target_id', '1.2'], ['target_id', '18446744073709551616'],
            ['admin_id', ['1']], ['action', ['x']], ['action', str_repeat('x', 51)], ['target_type', str_repeat('x', 101)],
            ['date_from', '2026-02-30'], ['date_to', '2026-1-1'], ['date_from', '2026-11-01T00:00:00Z'],
            ['page', 0], ['page', 'x'], ['page', 10001], ['page', PHP_INT_MAX],
            ['date_from', '0999-12-31'], ['date_to', '0999-12-31'],
            ['date_from', '9999-12-31'], ['date_to', '9999-12-31'],
            ['date_from', '0000-01-01'], ['date_to', '10000-01-01'],
            ['date_from', '2027-02-29'], ['date_to', '1900-02-29'], ['date_to', ['2026-11-01']],
            ['per_page', 0], ['per_page', 101], ['sort', 'id'], ['direction', 'asc'], ['unknown', 'x']];
    }

    public function test_reversed_dates_are_invalid(): void
    {
        $this->authenticate();
        $this->getJson(self::URL . '?date_from=2026-11-02&date_to=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('date_to');
    }

    public function test_mysql_session_is_explicitly_utc(): void
    {
        $this->assertSame('+00:00', DB::connection()->getConfig('timezone'));
        $this->assertSame('+00:00', DB::selectOne('SELECT @@session.time_zone AS zone')->zone);
    }

    #[DataProvider('validDateRanges')]
    public function test_date_ranges_include_start_and_exclude_next_midnight(string $from, string $to): void
    {
        $this->authenticate();
        $start = \Carbon\CarbonImmutable::parse($from, 'UTC');
        $end = \Carbon\CarbonImmutable::parse($to, 'UTC')->addDay();
        $this->log(['created_at' => $start->subSecond()->format('Y-m-d H:i:s')]);
        $first = $this->log(['created_at' => $start->format('Y-m-d H:i:s')]);
        $last = $this->log(['created_at' => $end->subSecond()->format('Y-m-d H:i:s')]);
        $this->log(['created_at' => $end->format('Y-m-d H:i:s')]);
        $response = $this->getJson(self::URL . '?' . http_build_query(['date_from' => $from, 'date_to' => $to]))->assertOk();
        $this->assertSame([$last, $first], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.1.created_at', $start->toISOString());
    }

    public static function validDateRanges(): array
    {
        return [['2026-01-31', '2026-02-01'], ['2026-12-31', '2027-01-01'],
            ['2028-02-28', '2028-02-29'], ['2028-02-29', '2028-02-29']];
    }

    #[DataProvider('openDateRanges')]
    public function test_open_and_extreme_date_ranges(array $query, bool $matches): void
    {
        $this->authenticate();
        $id = $this->log();
        $response = $this->getJson(self::URL . '?' . http_build_query($query))->assertOk();
        $this->assertSame($matches ? [$id] : [], array_column($response->json('data'), 'id'));
    }

    public static function openDateRanges(): array
    {
        return [[['date_from' => '1000-01-01'], true], [['date_to' => '9999-12-30'], true],
            [['date_from' => '9999-12-30'], false], [['date_to' => '1000-01-01'], false],
            [['date_from' => '2026-11-01'], true], [['date_from' => '2026-11-02'], false],
            [['date_to' => '2026-11-01'], true], [['date_to' => '2026-10-31'], false]];
    }

    public function test_maximum_page_is_supported(): void
    {
        $this->authenticate();
        $this->log();
        $this->getJson(self::URL . '?page=10000&per_page=100')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.current_page', 10000)
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_permission_precedes_invalid_date_and_page(): void
    {
        $this->authenticate(false);
        foreach (['date_to=9999-12-31', 'page=10001'] as $query) {
            app('auth')->forgetGuards();
            $this->getJson(self::URL . '?' . $query)->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
        }
    }

    public function test_missing_invalid_and_revoked_tokens_return_401(): void
    {
        $this->getJson(self::URL)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $actor = $this->authenticate();
        app('auth')->forgetGuards();
        $this->withToken('invalid')->getJson(self::URL)->assertUnauthorized();
        $token = $actor->createToken('revoked');
        $token->accessToken->delete();
        app('auth')->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson(self::URL)->assertUnauthorized();
    }

    public function test_permission_precedes_validation_and_disabled_actor_is_rejected(): void
    {
        $this->authenticate(false);
        $this->getJson(self::URL . '?sort=invalid')->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
        app('auth')->forgetGuards();
        $actor = $this->authenticate(true, false);
        $this->getJson(self::URL)->assertForbidden()->assertExactJson(['message' => 'Administrator account is disabled.']);
        $this->assertSame(1, $actor->tokens()->count());
    }

    public function test_session_cannot_replace_bearer_authentication(): void
    {
        $actor = $this->authenticate();
        config(['sanctum.guard' => ['admin']]);
        app('auth')->forgetGuards();
        $this->actingAs($actor, 'admin');
        $this->withToken('invalid')->getJson(self::URL)->assertUnauthorized();
    }
}
