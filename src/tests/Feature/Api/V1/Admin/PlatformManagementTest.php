<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformManagementTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/v1/admin/platforms';

    private function authenticate(array $permissions = ['platforms.view', 'platforms.manage'], bool $active = true): Admin
    {
        app('auth')->forgetGuards();
        $actor = Admin::factory()->create(['is_active' => $active]);
        $role = 'platform_test_' . $actor->id;
        DB::table('admin_roles')->insert(['admin_id' => $actor->id, 'role' => $role]);
        foreach ($permissions as $permission) {
            DB::table('roles_permissions')->insert(['role' => $role, 'permission' => $permission]);
        }
        $this->withToken($actor->createToken('platform-test')->plainTextToken);

        return $actor;
    }

    private function data(Platform $platform): array
    {
        return [
            'id' => $platform->id, 'code' => $platform->code, 'name' => $platform->name,
            'is_active' => $platform->is_active,
            'created_at' => $platform->created_at->toISOString(),
            'updated_at' => $platform->updated_at->toISOString(),
        ];
    }

    public function test_create_detail_name_and_status_response_contracts(): void
    {
        $this->authenticate();
        $response = $this->postJson(self::URL, ['code' => 'PlatformA', 'name' => '  Platform A  '])->assertCreated();
        $platform = Platform::findOrFail($response->json('data.id'));
        $this->assertTrue($platform->is_active);
        $this->assertSame('PlatformA', $platform->code);
        $this->assertSame('Platform A', $platform->name);
        $response->assertHeader('Location', url(self::URL . '/' . $platform->id))
            ->assertExactJson(['data' => $this->data($platform)]);
        $this->getJson(self::URL . '/' . $platform->id)->assertOk()->assertExactJson(['data' => $this->data($platform)]);
        $this->putJson(self::URL . '/' . $platform->id, ['name' => ' New name '])->assertOk()
            ->assertExactJson(['data' => $this->data($platform->refresh())]);
        $this->assertSame('New name', $platform->name);
        $this->patchJson(self::URL . '/' . $platform->id . '/status', ['is_active' => false])->assertOk()
            ->assertExactJson(['data' => $this->data($platform->refresh())]);
        $this->assertFalse($platform->is_active);
    }

    public function test_explicit_inactive_creation_and_maximum_lengths(): void
    {
        $this->authenticate();
        $this->postJson(self::URL, ['code' => str_repeat('A', 50), 'name' => str_repeat('名', 100), 'is_active' => false])
            ->assertCreated()->assertJsonPath('data.is_active', false)->assertJsonPath('data.code', str_repeat('A', 50));
        $this->postJson(self::URL, ['code' => 'a.Z_0-9', 'name' => 'Valid'])->assertCreated();
    }

    public function test_list_pagination_and_default_order(): void
    {
        $this->authenticate();
        $platforms = Platform::factory()->count(23)->create();
        $ids = $platforms->pluck('id')->reverse()->values()->all();
        $response = $this->getJson(self::URL)->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta'])
            ->assertJsonPath('meta.total', 23)->assertJsonPath('meta.per_page', 20);
        $this->assertSame(array_slice($ids, 0, 20), array_column($response->json('data'), 'id'));
        $this->assertSame($this->data($platforms->last()), $response->json('data.0'));
        $response = $this->getJson(self::URL . '?page=2')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(array_slice($ids, 20), array_column($response->json('data'), 'id'));
        $this->getJson(self::URL . '?per_page=100&page=10000')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.current_page', 10000)->assertJsonPath('meta.per_page', 100);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_grouped_search_and_literal_like_escaping(): void
    {
        $this->authenticate();
        $code = Platform::factory()->create(['code' => 'A_1', 'name' => 'Unrelated']);
        $name = Platform::factory()->create(['code' => 'NameMatch', 'name' => 'A_1']);
        Platform::factory()->create(['code' => 'A_1_disabled', 'is_active' => false]);
        Platform::factory()->create(['code' => 'AX1', 'name' => 'Other']);
        $response = $this->getJson(self::URL . '?search=A_1&is_active=1&sort=id&direction=asc')->assertOk();
        $this->assertSame([$code->id, $name->id], array_column($response->json('data'), 'id'));
        $literal = Platform::factory()->create(['name' => 'Percent%_!\\name']);
        Platform::factory()->create(['name' => 'PercentXXname']);
        $response = $this->getJson(self::URL . '?' . http_build_query(['search' => '%_!\\']))->assertOk();
        $this->assertSame([$literal->id], array_column($response->json('data'), 'id'));
    }

    #[DataProvider('sorts')]
    public function test_sort_allowlist_and_deterministic_ties(string $sort, string $direction): void
    {
        $this->authenticate();
        $first = Platform::factory()->create(['code' => 'A', 'name' => 'Same', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);
        $second = Platform::factory()->create(['code' => 'B', 'name' => 'Same', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);
        $expected = $direction === 'asc' ? [$first->id, $second->id] : [$second->id, $first->id];
        $response = $this->getJson(self::URL . '?' . http_build_query(compact('sort', 'direction')))->assertOk();
        $this->assertSame($expected, array_column($response->json('data'), 'id'));
    }

    public static function sorts(): array
    {
        $cases = [];
        foreach (['id', 'code', 'name', 'created_at', 'updated_at'] as $sort) {
            foreach (['asc', 'desc'] as $direction) {
                $cases[] = [$sort, $direction];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_list_queries(string $field, mixed $value): void
    {
        $this->authenticate();
        $this->getJson(self::URL . '?' . http_build_query([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame(0, AuditLog::count());
    }

    public static function invalidQueries(): array
    {
        return [
            ['page', 0], ['page', 10001], ['page', 'bad'], ['page', [1]], ['per_page', 0], ['per_page', 101],
            ['sort', 'password'], ['direction', 'sideways'], ['search', ['x']], ['search', str_repeat('x', 101)],
            ['is_active', 'yes'], ['unknown', 'x'], ['code', 'A'],
        ];
    }

    public function test_exact_duplicate_code_rejected_and_case_variants_preserved(): void
    {
        $this->authenticate();
        foreach (['PlatformA', 'platforma'] as $code) {
            $this->postJson(self::URL, ['code' => $code, 'name' => $code])->assertCreated()->assertJsonPath('data.code', $code);
        }
        $this->postJson(self::URL, ['code' => 'PlatformA', 'name' => 'Duplicate'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(2, Platform::count());
        $this->assertSame(2, AuditLog::count());
    }

    #[DataProvider('invalidCreation')]
    public function test_create_validation_rejects_invalid_and_unknown_fields(array $payload, string $field): void
    {
        $this->authenticate();
        $this->postJson(self::URL, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame(0, Platform::count());
        $this->assertSame(0, AuditLog::count());
    }

    public static function invalidCreation(): array
    {
        $valid = ['code' => 'PlatformA', 'name' => 'Platform'];
        $cases = [[[], 'code'], [[], 'name']];
        foreach (['code' => [null, '', ' ', 'bad code', '平台', 'bad/code', str_repeat('a', 51), ['x']], 'name' => [null, '', '   ', str_repeat('名', 101), ['x']], 'is_active' => [null, 'yes', 2, []]] as $field => $values) {
            foreach ($values as $value) {
                $cases[] = [array_replace($valid, [$field => $value]), $field];
            }
        }
        foreach (['id' => 1, 'platform_id' => 2, 'roles' => [], 'permissions' => null, 'created_at' => 'x', 'unexpected' => 'x'] as $field => $value) {
            $cases[] = [$valid + [$field => $value], $field];
        }

        return $cases;
    }

    #[DataProvider('invalidUpdates')]
    public function test_update_field_allowlists_preserve_platform(string $operation, array $payload, string $field): void
    {
        $this->authenticate();
        $platform = Platform::factory()->create(['code' => 'PlatformA']);
        $before = $platform->refresh()->getAttributes();
        $url = self::URL . '/' . $platform->id;
        ($operation === 'put' ? $this->putJson($url, $payload) : $this->patchJson($url . '/status', $payload))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $platform->refresh()->getAttributes());
        $this->assertSame(0, AuditLog::count());
    }

    public static function invalidUpdates(): array
    {
        return [
            ['put', [], 'name'], ['put', ['name' => ' '], 'name'], ['put', ['name' => null], 'name'],
            ['put', ['name' => str_repeat('a', 101)], 'name'], ['put', ['name' => []], 'name'],
            ['put', ['name' => 'Valid', 'code' => 'PlatformA'], 'code'],
            ['put', ['name' => 'Valid', 'code' => 'Changed'], 'code'],
            ['put', ['name' => 'Valid', 'code' => null], 'code'],
            ['put', ['name' => 'Valid', 'is_active' => false], 'is_active'],
            ['put', ['name' => 'Valid', 'roles' => []], 'roles'],
            ['patch', [], 'is_active'], ['patch', ['is_active' => null], 'is_active'],
            ['patch', ['is_active' => 'false'], 'is_active'], ['patch', ['is_active' => 2], 'is_active'],
            ['patch', ['is_active' => []], 'is_active'],
            ['patch', ['is_active' => false, 'code' => 'PlatformA'], 'code'],
            ['patch', ['is_active' => false, 'name' => 'Changed'], 'name'],
            ['patch', ['is_active' => false, 'unknown' => null], 'unknown'],
        ];
    }

    public function test_update_query_injection_and_status_filter(): void
    {
        $this->authenticate();
        $platform = Platform::factory()->create();
        $this->putJson(self::URL . '/' . $platform->id . '?code=' . $platform->code, ['name' => 'New'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $disabled = Platform::factory()->create(['is_active' => false]);
        $response = $this->getJson(self::URL . '?is_active=0')->assertOk();
        $this->assertSame([$disabled->id], array_column($response->json('data'), 'id'));
    }

    #[DataProvider('endpoints')]
    public function test_authentication_and_permission_precede_validation(string $method, string $path): void
    {
        $url = self::URL . $path;
        $this->json($method, $url, [])->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        app('auth')->forgetGuards();
        $this->withToken('invalid')->json($method, $url, [])->assertUnauthorized();
        $actor = $this->authenticate();
        $actor->tokens()->delete();
        app('auth')->forgetGuards();
        $this->json($method, $url, [])->assertUnauthorized();
        $this->authenticate([]);
        $this->json($method, $url, [])->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
        $this->authenticate(['platforms.view', 'platforms.manage'], false);
        $this->json($method, $url, [])->assertForbidden()
            ->assertExactJson(['message' => 'Administrator account is disabled.']);
        $this->assertSame(0, AuditLog::count());
    }

    public static function endpoints(): array
    {
        return [['GET', ''], ['GET', '/invalid'], ['POST', ''], ['PUT', '/invalid'], ['PATCH', '/invalid/status']];
    }

    public function test_view_and_manage_permissions_are_independent(): void
    {
        $this->authenticate(['platforms.view']);
        $platform = Platform::factory()->create();
        $this->getJson(self::URL)->assertOk();
        $this->getJson(self::URL . '/' . $platform->id)->assertOk();
        $this->postJson(self::URL, [])->assertForbidden();
        $this->putJson(self::URL . '/' . $platform->id, ['name' => 'New'])->assertForbidden();
        $this->patchJson(self::URL . '/' . $platform->id . '/status', ['is_active' => false])->assertForbidden();
        $this->authenticate(['platforms.manage']);
        $this->getJson(self::URL)->assertForbidden();
        $this->getJson(self::URL . '/' . $platform->id)->assertForbidden();
        $this->putJson(self::URL . '/' . $platform->id, ['name' => 'New'])->assertOk();
    }

    #[DataProvider('endpoints')]
    public function test_session_cannot_replace_persisted_bearer_token(string $method, string $path): void
    {
        $actor = $this->authenticate();
        $this->withoutHeader('Authorization');
        $this->actingAs($actor, 'admin')->json($method, self::URL . $path)->assertUnauthorized();
    }

    #[DataProvider('invalidIds')]
    public function test_safe_404_precedes_body_validation(string $id): void
    {
        $this->authenticate();
        foreach (['GET', 'PUT', 'PATCH'] as $method) {
            $this->json($method, self::URL . '/' . $id . ($method === 'PATCH' ? '/status' : ''), [])
                ->assertNotFound()->assertExactJson(['message' => 'Platform not found.']);
        }
        $this->assertSame(0, AuditLog::count());
    }

    public static function invalidIds(): array
    {
        return [['0'], ['-1'], ['abc'], ['1.5'], ['01'], ['18446744073709551616'], ['18446744073709551615']];
    }

    public function test_status_transitions_and_noops_preserve_players_and_tokens(): void
    {
        $actor = $this->authenticate();
        $player = User::factory()->create();
        $platform = $player->platform;
        $playerBefore = $player->refresh()->getAttributes();
        $tokensBefore = $actor->tokens()->pluck('id')->all();
        $this->putJson(self::URL . '/' . $platform->id, ['name' => $platform->name])->assertOk();
        $this->patchJson(self::URL . '/' . $platform->id . '/status', ['is_active' => true])->assertOk();
        $this->assertSame(0, AuditLog::count());
        foreach ([false, false, true, true] as $active) {
            $this->patchJson(self::URL . '/' . $platform->id . '/status', ['is_active' => $active])->assertOk()
                ->assertJsonPath('data.is_active', $active);
        }
        $this->assertSame(['platform.disabled', 'platform.enabled'], AuditLog::orderBy('id')->pluck('action')->all());
        $this->assertSame($playerBefore, $player->refresh()->getAttributes());
        $this->assertSame($tokensBefore, $actor->tokens()->pluck('id')->all());
        $this->assertTrue($player->platform->is($platform));
        $this->assertSame($platform->code, $platform->refresh()->code);
    }

    public function test_delete_is_not_available(): void
    {
        $this->authenticate();
        $player = User::factory()->create();
        $this->deleteJson(self::URL . '/' . $player->platform_id)->assertStatus(405);
        $this->assertModelExists($player);
        $this->assertModelExists($player->platform);
    }
}
