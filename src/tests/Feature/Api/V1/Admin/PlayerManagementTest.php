<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlayerManagementTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/v1/admin/players';

    private function authenticate(array $permissions = ['players.view', 'players.manage'], bool $active = true): Admin
    {
        $actor = Admin::factory()->create(['is_active' => $active]);
        $role = 'player_test_' . $actor->id;
        DB::table('admin_roles')->insert(['admin_id' => $actor->id, 'role' => $role]);
        foreach ($permissions as $permission) {
            DB::table('roles_permissions')->insert(['role' => $role, 'permission' => $permission]);
        }
        Auth::forgetGuards();
        $this->withToken($actor->createToken('player-test')->plainTextToken);

        return $actor;
    }

    public function test_resource_cross_platform_identity_case_and_trim_contract(): void
    {
        $this->authenticate();
        $a = User::factory()->create(['external_user_id' => 'Player-A', 'last_login_at' => '2026-11-01 12:00:00']);
        $b = User::factory()->create(['external_user_id' => 'Player-A']);
        User::factory()->create(['platform_id' => $a->platform_id, 'external_user_id' => 'player-a']);
        $response = $this->getJson(self::URL . '/' . $a->id)->assertOk();
        $response->assertExactJson(['data' => [
            'id' => $a->id, 'platform_id' => $a->platform_id, 'external_user_id' => 'Player-A',
            'name' => null, 'status' => 1, 'last_login_at' => $a->last_login_at->utc()->toISOString(),
            'created_at' => $a->created_at->utc()->toISOString(), 'updated_at' => $a->updated_at->utc()->toISOString(),
        ]]);
        $this->getJson(self::URL . '?external_user_id=Player-A')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson(self::URL . '?external_user_id=player-a')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(self::URL . '?external_user_id=%20Player-A%20&platform_id=' . $a->platform_id)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $a->id);
        $this->patchJson(self::URL . '/' . $a->id . '/status', ['status' => 0])->assertOk();
        $this->assertSame(1, $b->fresh()->status);
    }

    public function test_pagination_sort_ties_and_grouped_literal_search(): void
    {
        $this->authenticate();
        $platform = Platform::factory()->create();
        $first = User::factory()->create(['platform_id' => $platform->id, 'external_user_id' => 'A!%_', 'name' => 'Same']);
        $second = User::factory()->create(['platform_id' => $platform->id, 'external_user_id' => 'B', 'name' => 'Same']);
        User::factory()->create(['name' => 'A!%_']);
        $this->getJson(self::URL . '?search=' . rawurlencode('!%_') . '&platform_id=' . $platform->id)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $first->id);
        $this->getJson(self::URL . '?search=' . rawurlencode("' OR 1=1 --"))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::URL . '?platform_id=' . $platform->id . '&sort=name&direction=asc&per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.0.id', $second->id)->assertJsonPath('meta.total', 2)
            ->assertJsonStructure(['data', 'links', 'meta']);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.per_page', 20);
        $this->getJson(self::URL . '?platform_id=18446744073709551615')->assertOk()->assertJsonPath('data', []);
    }

    #[DataProvider('sortFields')]
    public function test_primary_sort_and_id_ties_in_both_directions(string $sort): void
    {
        $this->authenticate();
        $lowPlatform = Platform::factory()->create();
        $highPlatform = Platform::factory()->create();
        $platforms = [$highPlatform->id, $lowPlatform->id, $lowPlatform->id, $highPlatform->id];
        $values = match ($sort) {
            'external_user_id', 'name' => ['Mid', 'Alpha', 'Alpha', 'Zulu'],
            'status' => [1, 0, 0, 1],
            'created_at', 'updated_at', 'last_login_at' => [
                '2026-02-01 00:00:00', '2026-01-01 00:00:00',
                '2026-01-01 00:00:00', '2026-03-01 00:00:00',
            ],
            default => [],
        };
        $ids = [];
        for ($index = 0; $index < 4; $index++) {
            $attributes = ['platform_id' => $platforms[$index]];
            if ($sort === 'external_user_id' && $index === 2) {
                // Equal external IDs can coexist on different platforms.
                $attributes['platform_id'] = $highPlatform->id;
            }
            if ($values !== []) {
                $attributes[$sort] = $values[$index];
            }
            $ids[] = User::factory()->create($attributes)->id;
        }
        // Primary values deliberately disagree with insertion/ID order.
        $ascending = $sort === 'id' ? $ids : [$ids[1], $ids[2], $ids[0], $ids[3]];
        foreach (['asc' => $ascending, 'desc' => array_reverse($ascending)] as $direction => $expected) {
            $response = $this->getJson(self::URL . '?sort=' . $sort . '&direction=' . $direction)->assertOk();
            $this->assertSame($expected, array_column($response->json('data'), 'id'));
        }
    }

    public static function sortFields(): array
    {
        return array_map(fn ($sort) => [$sort], [
            'id', 'platform_id', 'external_user_id', 'name', 'status',
            'last_login_at', 'created_at', 'updated_at',
        ]);
    }

    #[DataProvider('badQueries')]
    public function test_invalid_queries(string $query): void
    {
        $this->authenticate();
        $this->getJson(self::URL . '?' . $query)->assertUnprocessable();
        $this->assertSame(0, AuditLog::count());
    }

    public static function badQueries(): array
    {
        return array_map(fn ($query) => [$query], [
            'page=0', 'page=10001', 'per_page=101', 'per_page=0', 'page[]=1', 'platform_id=0',
            'platform_id=-1', 'platform_id=18446744073709551616', 'platform_id[]=1',
            'status=2', 'status[]=1', 'status=true', 'external_user_id[]=', 'external_user_id=',
            'search[]=x', 'sort=id%20desc', 'direction=sideways', 'unknown=1',
            'external_user_id=' . str_repeat('x', 101), 'search=' . str_repeat('x', 101),
        ]);
    }

    #[DataProvider('invalidBodies')]
    public function test_strict_json_status_and_field_allowlist(array $body): void
    {
        $this->authenticate();
        $player = User::factory()->create();
        $before = $player->refresh()->getAttributes();
        $this->patchJson(self::URL . '/' . $player->id . '/status', $body)->assertUnprocessable();
        $this->assertSame($before, $player->fresh()->getAttributes());
        $this->assertSame(0, AuditLog::count());
    }

    public static function invalidBodies(): array
    {
        $cases = array_map(fn ($value) => [['status' => $value]], [true, false, '0', '1', null, 2, -1, [], ['status' => 0]]);
        $cases[] = [[]];
        foreach (['platform_id', 'external_user_id', 'last_login_at', 'name', 'wallet', 'balance', 'id'] as $field) {
            $cases[] = [['status' => 0, $field => 'forbidden']];
        }

        return $cases;
    }

    public function test_float_non_json_and_query_status_are_rejected(): void
    {
        $this->authenticate();
        $player = User::factory()->create();
        $url = self::URL . '/' . $player->id . '/status';
        $server = $this->transformHeadersToServerVars($this->defaultHeaders);
        $server['CONTENT_TYPE'] = 'application/json';
        $server['HTTP_ACCEPT'] = 'application/json';
        $this->call('PATCH', $url, [], [], [], $server, '{"status":0.0}')->assertUnprocessable();
        $this->patch($url, ['status' => 0])->assertUnprocessable();
        $this->patchJson($url . '?status=0', ['status' => 0])->assertUnprocessable();
        $this->assertSame(1, $player->fresh()->status);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_unknown_status_conflict_and_noop_preserve_all_attributes(): void
    {
        $this->authenticate();
        foreach ([2, 255] as $status) {
            $player = User::factory()->create(['status' => $status]);
            $before = $player->refresh()->getAttributes();
            $this->getJson(self::URL . '/' . $player->id)->assertOk()->assertJsonPath('data.status', $status);
            $this->patchJson(self::URL . '/' . $player->id . '/status', ['status' => 1])
                ->assertStatus(409)->assertExactJson(['message' => 'Player status is not supported.']);
            $this->assertSame($before, $player->fresh()->getAttributes());
        }
        $player = User::factory()->create(['updated_at' => '2026-01-01 00:00:00']);
        $before = $player->refresh()->getAttributes();
        $this->patchJson(self::URL . '/' . $player->id . '/status', ['status' => 1])->assertOk();
        $this->assertSame($before, $player->fresh()->getAttributes());
        $this->assertSame(0, AuditLog::count());
    }

    #[DataProvider('invalidIds')]
    public function test_safe_404_before_body_validation(string $id): void
    {
        $this->authenticate();
        $this->getJson(self::URL . '/' . $id)->assertNotFound()->assertExactJson(['message' => 'Player not found.']);
        $this->patchJson(self::URL . '/' . $id . '/status', [])->assertNotFound()->assertExactJson(['message' => 'Player not found.']);
    }

    public static function invalidIds(): array
    {
        return [['0'], ['-1'], ['abc'], ['01'], ['18446744073709551616'], ['18446744073709551615']];
    }

    public function test_authentication_permission_precedence_and_revocation(): void
    {
        foreach ([['GET', ''], ['GET', '/invalid'], ['PATCH', '/invalid/status']] as [$method, $path]) {
            $this->json($method, self::URL . $path, [])->assertUnauthorized();
            $this->withToken('invalid')->json($method, self::URL . $path, [])->assertUnauthorized();
        }
        $actor = $this->authenticate([]);
        $this->getJson(self::URL . '?unknown=1')->assertForbidden();
        $this->patchJson(self::URL . '/invalid/status', [])->assertForbidden();
        $actor->tokens()->delete();
        Auth::forgetGuards();
        $this->getJson(self::URL)->assertUnauthorized();
        $this->authenticate(active: false);
        $this->getJson(self::URL)->assertForbidden();
        $this->assertSame(0, AuditLog::count());
    }

    public function test_permissions_are_independent_and_session_is_rejected(): void
    {
        $player = User::factory()->create();
        $actor = $this->authenticate(['players.view']);
        $this->getJson(self::URL)->assertOk();
        $this->patchJson(self::URL . '/' . $player->id . '/status', ['status' => 0])->assertForbidden();
        $this->authenticate(['players.manage']);
        $this->getJson(self::URL)->assertForbidden();
        $this->patchJson(self::URL . '/' . $player->id . '/status', ['status' => 0])->assertOk();
        $this->withoutToken();
        Auth::forgetGuards();
        $this->actingAs($actor, 'admin')->getJson(self::URL)->assertUnauthorized();
    }
    public function test_status_filter_defaults_and_no_creation_or_deletion_api(): void
    {
        $this->authenticate();
        $active = User::factory()->create();
        $disabled = User::factory()->create(['status' => 0]);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.id', $disabled->id);
        $this->getJson(self::URL . '?status=1')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $active->id);
        $this->getJson(self::URL . '?status=0')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $disabled->id);
        $this->postJson(self::URL, [])->assertStatus(405);
        $this->deleteJson(self::URL . '/' . $active->id)->assertStatus(405);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_transaction_reread_handles_disappearance(): void
    {
        $this->authenticate();
        $player = User::factory()->create();
        $deleted = false;
        // A single-connection lifecycle simulation, not a real concurrent transaction.
        $player->getConnection()->beforeExecuting(function (string $sql) use ($player, &$deleted): void {
            if (! $deleted && str_contains($sql, '`users`') && str_ends_with($sql, 'for update')) {
                $deleted = true;
                DB::table('users')->where('id', $player->id)->delete();
            }
        });
        $this->patchJson(self::URL . '/' . $player->id . '/status', ['status' => 0])
            ->assertNotFound()->assertExactJson(['message' => 'Player not found.']);
        $this->assertTrue($deleted);
        $this->assertSame(0, AuditLog::count());
    }

}
