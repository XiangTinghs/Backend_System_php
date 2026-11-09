<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Http\Resources\Api\V1\Platform\PlayerResource;
use App\Models\Admin;
use App\Models\PlatformApiIdempotency;
use App\Models\User;
use App\Services\PlayerResolveService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\PlatformJwtFixture;
use Tests\TestCase;

class PlayerResolveTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/v1/platform/players/resolve';
    private array $fixture;
    private string $token;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->startOfSecond());
        $this->fixture = PlatformJwtFixture::create();
        $this->token = PlatformJwtFixture::token($this->fixture);
        $this->key = (string) Str::uuid();
    }

    private function resolve(array $body = ['external_user_id' => 'Player001', 'name' => 'Player One'], ?string $key = null): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token)->withHeader('Idempotency-Key', $key ?? $this->key)->postJson(self::URL, $body);
    }

    public function test_create_exact_resource_defaults_and_atomic_completed_record(): void
    {
        $response = $this->resolve()->assertCreated();
        $player = User::where('platform_id', $this->fixture['platform']->id)->sole();
        $response->assertExactJson(['data' => ['id' => $player->id, 'external_user_id' => 'Player001', 'name' => 'Player One', 'status' => 1]]);
        $this->assertNull($player->last_login_at);
        $record = PlatformApiIdempotency::sole();
        $this->assertSame('completed', $record->state);
        $this->assertSame((string) $player->id, $record->player_id);
        $this->assertSame(201, $record->response_status);
        $this->assertSame($response->getContent(), $record->response_body);
        $this->assertTrue($record->expires_at->greaterThanOrEqualTo(now('UTC')->addHours(24)));
        $this->assertSame(PlayerResolveService::ENDPOINT, $record->endpoint);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_existing_player_is_not_updated_by_new_resolve_or_replay(): void
    {
        $player = User::factory()->create(['platform_id' => $this->fixture['platform']->id, 'external_user_id' => 'Player001',
            'name' => 'Original', 'last_login_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'])->refresh();
        $before = $player->getAttributes();
        $response = $this->resolve()->assertOk()->assertJsonPath('data.name', 'Original');
        $this->resolve()->assertOk()->assertContent($response->getContent());
        $this->resolve(['external_user_id' => 'Player001'], (string) Str::uuid())->assertOk()->assertJsonPath('data.name', 'Original');
        $this->assertSame($before, $player->refresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_exact_replay_with_reordered_body_and_uppercase_uuid_and_reusable_jwt(): void
    {
        $response = $this->resolve()->assertCreated();
        $recordBefore = PlatformApiIdempotency::sole()->getAttributes();
        $this->travel(299)->seconds();
        $this->resolve(['name' => 'Player One', 'external_user_id' => 'Player001'], strtoupper($this->key))
            ->assertCreated()->assertContent($response->getContent());
        $this->resolve(['external_user_id' => 'Another'], (string) Str::uuid())->assertCreated();
        $this->assertSame($recordBefore, PlatformApiIdempotency::where('idempotency_key', $this->key)->sole()->getAttributes());
        $this->travel(1)->seconds();
        $this->resolve()->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_different_content_same_key_conflicts_without_creating_or_updating_players(): void
    {
        $this->resolve()->assertCreated();
        $before = PlatformApiIdempotency::sole()->getAttributes();
        foreach ([['external_user_id' => 'Different', 'name' => 'Player One'], ['external_user_id' => 'Player001', 'name' => 'Changed']] as $body) {
            $this->resolve($body)->assertStatus(409)->assertExactJson(['message' => 'The Idempotency-Key was already used with different request content.']);
        }
        $this->assertSame($before, PlatformApiIdempotency::sole()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Player One', User::sole()->name);
    }

    public function test_omitted_null_and_empty_name_are_distinct_request_contents(): void
    {
        $first = $this->resolve(['external_user_id' => 'Player001'])->assertCreated()->assertJsonPath('data.name', null);
        $this->resolve(['external_user_id' => 'Player001'])->assertCreated()->assertContent($first->getContent());
        $this->resolve(['external_user_id' => 'Player001', 'name' => null])->assertStatus(409);
        $this->resolve(['external_user_id' => 'Player001', 'name' => ''])->assertStatus(409);
        $this->resolve(['external_user_id' => 'Second', 'name' => ''], (string) Str::uuid())->assertCreated()->assertJsonPath('data.name', '');
    }

    public function test_external_identity_is_case_sensitive_and_name_is_preserved(): void
    {
        $first = $this->resolve(['external_user_id' => 'Player001', 'name' => ' Player One '])->assertCreated()->assertJsonPath('data.name', ' Player One ');
        $second = $this->resolve(['external_user_id' => 'player001'], (string) Str::uuid())->assertCreated();
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('users', 2);
    }

    public function test_same_external_id_and_uuid_are_isolated_across_platforms_with_same_kid(): void
    {
        $first = $this->resolve()->assertCreated();
        $other = PlatformJwtFixture::create(1);
        $this->token = PlatformJwtFixture::token($other);
        $second = $this->resolve()->assertCreated();
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->resolve()->assertCreated()->assertContent($second->getContent());
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('platform_api_idempotencies', 2);
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_body_has_no_business_or_idempotency_writes(array $body): void
    {
        $this->resolve($body)->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('platform_api_idempotencies', 0);
    }

    public static function invalidBodies(): array
    {
        return array_map(fn ($body) => [$body], [[], ['external_user_id' => ''], ['external_user_id' => ' Player001'],
            ['external_user_id' => 'Player001 '], ['external_user_id' => "\tPlayer001"], ['external_user_id' => "Player001\n"],
            ['external_user_id' => "\u{00A0}Player001"], ['external_user_id' => "Player001\u{2003}"],
            ['external_user_id' => str_repeat('字', 101)], ['external_user_id' => 123], ['external_user_id' => true],
            ['external_user_id' => []], ['external_user_id' => 'Player001', 'platform_id' => 999],
            ['external_user_id' => 'Player001', 'status' => 1], ['external_user_id' => 'Player001', 'balance' => 0],
            ['external_user_id' => 'Player001', 'idempotency_key' => 'body-key'],
            ['external_user_id' => 'Player001', 'name' => str_repeat('字', 101)], ['external_user_id' => 'Player001', 'name' => false],
        ]);
    }

    public function test_maximum_unicode_lengths_are_supported(): void
    {
        $this->resolve(['external_user_id' => str_repeat('字', 100), 'name' => str_repeat('名', 100)])
            ->assertCreated()->assertJsonPath('data.external_user_id', str_repeat('字', 100));
    }

    #[DataProvider('invalidKeys')]
    public function test_missing_or_invalid_uuid_header_is_rejected(string $key): void
    {
        $this->resolve(key: $key)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertDatabaseCount('users', 0);
    }

    public static function invalidKeys(): array
    {
        return [[''], ['not-uuid'], [' ' . '550e8400-e29b-41d4-a716-446655440000'], ['550e8400-e29b-41d4-a716-446655440000,550e8400-e29b-41d4-a716-446655440000']];
    }

    public function test_json_object_content_type_and_no_query_parameters_are_required(): void
    {
        $this->withToken($this->token)->withHeader('Idempotency-Key', $this->key)->post(self::URL, ['external_user_id' => 'Player001'])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', $this->key)->postJson(self::URL . '?external_user_id=Player001', ['external_user_id' => 'Player001'])->assertUnprocessable();
        $this->call('POST', self::URL, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'HTTP_IDEMPOTENCY_KEY' => $this->key, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{invalid')
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_disabled_player_is_forbidden_and_error_is_not_cached(): void
    {
        $player = User::factory()->create(['platform_id' => $this->fixture['platform']->id, 'external_user_id' => 'Player001', 'status' => 0]);
        $this->resolve()->assertForbidden()->assertExactJson(['message' => 'Player is disabled.']);
        $this->assertDatabaseCount('platform_api_idempotencies', 0);
        $player->update(['status' => 1]);
        $this->resolve()->assertOk();
    }

    public function test_replay_cannot_bypass_current_player_status_and_restores_original_result_after_reenable(): void
    {
        $first = $this->resolve()->assertCreated();
        $record = PlatformApiIdempotency::sole()->getAttributes();
        User::sole()->update(['status' => 0]);
        $this->resolve()->assertForbidden()->assertExactJson(['message' => 'Player is disabled.']);
        $this->assertSame($record, PlatformApiIdempotency::sole()->getAttributes());
        User::sole()->update(['status' => 1]);
        $this->resolve()->assertCreated()->assertContent($first->getContent());
    }

    public function test_unknown_nonactive_player_states_fail_closed(): void
    {
        User::factory()->create(['platform_id' => $this->fixture['platform']->id, 'external_user_id' => 'Player001', 'status' => 2]);
        $this->resolve()->assertForbidden();
        $this->assertSame(2, User::sole()->status);
    }

    public function test_platform_forbidden_only_after_valid_signature_claims_and_key(): void
    {
        $this->fixture['platform']->update(['is_active' => false]);
        $this->resolve()->assertForbidden()->assertExactJson(['message' => 'Platform is disabled.']);
        $this->token = PlatformJwtFixture::token($this->fixture, ['exp' => now()->timestamp]);
        $this->resolve()->assertUnauthorized();
        $this->token = PlatformJwtFixture::token($this->fixture, ['sub' => 'wrong']);
        $this->resolve()->assertUnauthorized();
        $this->token = PlatformJwtFixture::token($this->fixture);
        [$header, $body, $signature] = explode('.', $this->token);
        $signature[0] = $signature[0] === 'a' ? 'b' : 'a';
        $this->token = "$header.$body.$signature";
        $this->resolve()->assertUnauthorized();
        $this->token = PlatformJwtFixture::token($this->fixture);
        $this->fixture['key']->update(['is_active' => false]);
        $this->resolve()->assertUnauthorized();
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('credentialChanges')]
    public function test_replay_rechecks_current_platform_and_key_state(string $change): void
    {
        $this->resolve()->assertCreated();
        match ($change) {
            'platform' => $this->fixture['platform']->update(['is_active' => false]),
            'key disabled' => $this->fixture['key']->update(['is_active' => false]),
            'key revoked' => $this->fixture['key']->forceFill(['revoked_at' => now()])->save(),
            'key expired' => $this->fixture['key']->update(['valid_until' => now()]),
        };
        $response = $this->resolve();
        $change === 'platform' ? $response->assertForbidden() : $response->assertUnauthorized();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('platform_api_idempotencies', 1);
    }

    public static function credentialChanges(): array
    {
        return [['platform'], ['key disabled'], ['key revoked'], ['key expired']];
    }

    public function test_admin_and_platform_authentication_remain_separate_and_auth_precedes_validation(): void
    {
        $this->postJson(self::URL, [])->assertUnauthorized();
        $admin = Admin::factory()->create();
        $adminToken = $admin->createToken('test')->plainTextToken;
        $this->withToken($adminToken)->postJson(self::URL, [])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson('/api/v1/admin/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson('/api/v1/admin/platforms')->assertUnauthorized();
        $this->assertSame(1, $admin->tokens()->count());
    }

    public function test_expired_completed_record_is_reused_atomically_without_deleting_history_early(): void
    {
        $first = $this->resolve()->assertCreated();
        $id = PlatformApiIdempotency::sole()->id;
        $this->travel(24)->hours();
        $this->token = PlatformJwtFixture::token($this->fixture);
        $this->resolve()->assertCreated()->assertContent($first->getContent());
        $this->travel(PlayerResolveService::RETENTION_HOURS - 24)->hours();
        $this->token = PlatformJwtFixture::token($this->fixture);
        $this->resolve(['external_user_id' => 'Second'])->assertCreated();
        $this->assertDatabaseCount('platform_api_idempotencies', 1);
        $this->assertSame($id, PlatformApiIdempotency::sole()->id);
        $this->assertDatabaseCount('users', 2);
        $this->assertSame('completed', PlatformApiIdempotency::sole()->state);
    }

    public function test_interrupted_response_after_commit_can_be_retried_without_duplicate_player(): void
    {
        DisconnectAfterResolveCommit::$drop = true;
        Route::getRoutes()->getByName('platform.players.resolve')->middleware(DisconnectAfterResolveCommit::class);
        $this->withoutExceptionHandling();
        try {
            $this->resolve();
            $this->fail('Expected simulated response interruption.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated lost response after commit.', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 1);
        $stored = PlatformApiIdempotency::sole();
        $this->resolve()->assertCreated()->assertContent($stored->response_body);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_replay_preserves_saved_response_even_if_player_name_changes_elsewhere(): void
    {
        $first = $this->resolve()->assertCreated();
        $player = User::sole();
        $player->update(['name' => 'Changed elsewhere']);
        $before = $player->refresh()->getAttributes();
        $this->resolve()->assertCreated()->assertContent($first->getContent());
        $this->assertSame($before, $player->refresh()->getAttributes());
        $this->resolve(key: (string) Str::uuid())->assertOk()->assertJsonPath('data.name', 'Changed elsewhere');
    }

    public function test_replay_rechecks_expiration_after_player_lock_wait(): void
    {
        $this->resolve()->assertCreated();
        $record = PlatformApiIdempotency::sole()->getAttributes();
        $changed = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$changed): void {
            if (! $changed && str_contains($sql, '`users`') && str_ends_with($sql, 'for update')) {
                $changed = true;
                $this->travel(300)->seconds();
            }
        });
        $this->resolve()->assertUnauthorized();
        $this->assertTrue($changed);
        $this->assertSame($record, PlatformApiIdempotency::sole()->getAttributes());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_result_persistence_failure_rolls_back_player_and_processing_record(): void
    {
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (str_starts_with($sql, 'update `platform_api_idempotencies`')) {
                throw new QueryException('mysql', $sql, [], new \PDOException('Result persistence failure'));
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->resolve();
            $this->fail('Expected persistence failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Result persistence failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('platform_api_idempotencies', 0);
    }

    public function test_real_unique_conflict_is_reread_without_updating_competing_player(): void
    {
        $dispatcher = User::getEventDispatcher();
        User::setEventDispatcher(clone $dispatcher);
        try {
            User::creating(function (User $player): void {
                DB::table('users')->insert(['platform_id' => $player->platform_id, 'external_user_id' => $player->external_user_id,
                    'name' => 'Competing player', 'status' => 1]);
            });
            $this->resolve()->assertOk()->assertJsonPath('data.name', 'Competing player');
            $this->assertDatabaseCount('users', 1);
            $this->assertSame(200, PlatformApiIdempotency::sole()->response_status);
        } finally {
            User::setEventDispatcher($dispatcher);
        }
    }

    public function test_unrelated_unique_errors_are_not_swallowed(): void
    {
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (str_starts_with($sql, 'insert into `users`')) {
                $previous = new \PDOException('Unrelated player SQL failure');
                $previous->errorInfo = ['23000', 1062, "Duplicate entry for key 'unrelated_unique'"];
                throw new QueryException('mysql', $sql, [], $previous);
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->resolve();
            $this->fail('Expected unrelated SQL failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Unrelated player SQL failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('platform_api_idempotencies', 0);
    }

    public function test_incomplete_persisted_record_fails_closed_instead_of_guessing_completion(): void
    {
        PlatformApiIdempotency::create(['platform_id' => $this->fixture['platform']->id, 'endpoint' => PlayerResolveService::ENDPOINT,
            'idempotency_key' => $this->key, 'request_hash' => str_repeat('a', 64), 'state' => 'processing', 'expires_at' => now()->subDay()]);
        $this->resolve()->assertStatus(409)->assertExactJson(['message' => 'The request is not complete. Retry later.']);
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('authorizationWaits')]
    public function test_expiry_while_waiting_in_transaction_is_rechecked_and_rolled_back(string $at, string $credential): void
    {
        if ($credential === 'key') {
            $this->fixture['key']->update(['valid_until' => now()->addSecond()]);
        }
        $changed = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($at, $credential, &$changed): void {
            if (! $changed && str_contains($sql, $at === 'platform' ? '`platforms`' : '`users`') && str_ends_with($sql, 'for update')) {
                $changed = true;
                $this->travel($credential === 'key' ? 1 : 300)->seconds();
            }
        });
        $this->resolve()->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertTrue($changed);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('platform_api_idempotencies', 0);
    }

    public static function authorizationWaits(): array
    {
        return [['platform', 'jwt'], ['player', 'jwt'], ['player', 'key']];
    }

    public function test_idempotency_scope_is_enforced_by_database_and_includes_endpoint(): void
    {
        $this->resolve()->assertCreated();
        $original = PlatformApiIdempotency::sole();
        try {
            $original->replicate()->save();
            $this->fail('Expected unique idempotency scope conflict.');
        } catch (QueryException $exception) {
            $this->assertSame(1062, (int) $exception->errorInfo[1]);
            $this->assertStringContainsString('platform_api_idempotencies_scope_unique', $exception->errorInfo[2]);
        }
        $differentEndpoint = $original->replicate();
        $differentEndpoint->endpoint = 'another.operation';
        $differentEndpoint->save();
        $this->resolve()->assertCreated()->assertContent($original->response_body);
        $this->assertDatabaseCount('platform_api_idempotencies', 2);
    }

    public function test_player_resource_preserves_large_unsigned_id_without_inserting_large_ids(): void
    {
        $player = (new User())->setRawAttributes(['id' => '9223372036854775808', 'external_user_id' => 'Large', 'name' => null, 'status' => 1], true);
        $this->assertSame('9223372036854775808', (new PlayerResource($player))->resolve()['id']);
    }
}

class DisconnectAfterResolveCommit
{
    public static bool $drop = false;

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (self::$drop) {
            self::$drop = false;
            throw new RuntimeException('Simulated lost response after commit.');
        }
        return $response;
    }
}
