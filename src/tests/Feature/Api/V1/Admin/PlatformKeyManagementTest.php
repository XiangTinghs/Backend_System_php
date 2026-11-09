<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Platform;
use App\Models\PlatformJwtIssuer;
use App\Models\PlatformJwtKey;
use App\Services\AuditLogService;
use App\Support\RsaPublicKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PlatformKeyManagementTest extends TestCase
{
    use DatabaseTransactions;

    private static string $public;
    private Platform $platform;
    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        if (! isset(self::$public)) {
            $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::$public = openssl_pkey_get_details($pair)['key'];
        }
        $this->platform = Platform::factory()->create();
        $this->url = '/api/v1/admin/platforms/' . $this->platform->id . '/keys';
    }

    private function authenticate(array $permissions = ['platforms.keys.register', 'platforms.keys.view', 'platforms.keys.manage', 'platforms.keys.revoke', 'audit-logs.view'], bool $active = true): Admin
    {
        $admin = Admin::factory()->create(['is_active' => $active]);
        $role = 'keys_' . $admin->id;
        DB::table('admin_roles')->insert(['admin_id' => $admin->id, 'role' => $role]);
        foreach ($permissions as $permission) {
            DB::table('roles_permissions')->insert(['role' => $role, 'permission' => $permission]);
        }
        $this->withToken($admin->createToken('keys-test')->plainTextToken);

        return $admin;
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['issuer' => 'platform-a', 'kid' => 'key-001', 'public_key' => self::$public,
            'valid_from' => now('UTC')->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'valid_until' => now('UTC')->addDay()->format('Y-m-d\TH:i:s\Z')], $overrides);
    }

    private function differentPublic(): string
    {
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        return openssl_pkey_get_details($pair)['key'];
    }

    private function register(): string
    {
        return (string) $this->postJson($this->url, $this->input())->assertCreated()->json('data.id');
    }

    public function test_registration_rotation_status_revoke_and_audit_contracts(): void
    {
        $actor = $this->authenticate();
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::9']);
        $response = $this->postJson($this->url, $this->input())->assertCreated();
        $id = (string) $response->json('data.id');
        $key = PlatformJwtKey::findOrFail($id);
        $response->assertHeader('Location', url($this->url . '/' . $id));
        $this->getJson($this->url . '/' . $id)->assertOk()->assertExactJson($response->json());
        $this->assertSame(['id', 'platform_id', 'issuer', 'kid', 'fingerprint', 'is_active', 'valid_from', 'valid_until', 'revoked_at', 'created_at', 'updated_at'], array_keys($response->json('data')));
        $this->assertSame('platform-a', $response->json('data.issuer'));
        $this->assertTrue($response->json('data.is_active'));
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', self::$public));
        $this->assertSame(hash('sha256', $der), $response->json('data.fingerprint'));
        $this->assertSame($key->valid_from->utc()->toISOString(), $response->json('data.valid_from'));
        $this->assertSame($key->valid_until->utc()->toISOString(), $response->json('data.valid_until'));
        $this->assertNull($response->json('data.revoked_at'));
        $created = AuditLog::firstOrFail();
        $this->assertNull($created->old_values);
        $this->assertEquals(['issuer' => 'platform-a', 'kid' => 'key-001', 'fingerprint' => $key->fingerprint,
            'is_active' => true, 'valid_from' => $key->valid_from->utc()->toISOString(), 'valid_until' => $key->valid_until->utc()->toISOString()], $created->new_values);
        $this->postJson($this->url, $this->input(['kid' => 'key-002', 'public_key' => $this->differentPublic()]))->assertCreated();
        $this->getJson($this->url . '?page=1&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson($this->url . '?page=2&per_page=1')->assertOk()->assertJsonPath('data.0.kid', 'key-002');
        $this->patchJson($this->url . '/' . $id . '/status', ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->patchJson($this->url . '/' . $id . '/status', ['is_active' => false])->assertOk();
        $this->patchJson($this->url . '/' . $id . '/status', ['is_active' => true])->assertOk();
        $this->postJson($this->url . '/' . $id . '/revoke')->assertOk()->assertJsonPath('data.is_active', false);
        $revokedAt = $key->refresh()->revoked_at->utc()->toISOString();
        $this->postJson($this->url . '/' . $id . '/revoke')->assertOk()->assertJsonPath('data.revoked_at', $revokedAt);
        $this->patchJson($this->url . '/' . $id . '/status', ['is_active' => true])->assertStatus(409)
            ->assertExactJson(['message' => 'Platform key has been revoked.']);
        $logs = AuditLog::orderBy('id')->get();
        $this->assertSame(['platform.key_registered', 'platform.key_registered', 'platform.key_disabled', 'platform.key_enabled', 'platform.key_revoked'], $logs->pluck('action')->all());
        $this->assertSame(['is_active' => true, 'revoked_at' => null], $logs->last()->old_values);
        $this->assertSame(['is_active' => false, 'revoked_at' => $revokedAt], $logs->last()->new_values);
        foreach ($logs as $log) {
            $this->assertSame($actor->id, $log->admin_id);
            $this->assertSame('platform_key', $log->target_type);
            $this->assertSame('2001:db8::9', $log->ip_address);
        }
        $audit = $this->getJson('/api/v1/admin/audit-logs?target_type=platform_key')->assertOk()->assertJsonCount(5, 'data');
        $this->assertStringNotContainsString('BEGIN PUBLIC KEY', $audit->getContent());
        $this->assertStringNotContainsString('public_key', $audit->getContent());
        $this->assertSame(1, $actor->tokens()->count());
        $this->assertSame(1, PlatformJwtIssuer::count());
        $this->assertSame(2, $this->platform->jwtKeys()->count());
        $this->assertSame('platform-a', $this->platform->jwtIssuer->issuer);
    }

    public function test_issuer_and_kid_uniqueness_preserve_existing_keys_and_bindings(): void
    {
        $this->authenticate();
        $this->register();
        $this->postJson($this->url, $this->input())->assertUnprocessable()->assertJsonValidationErrors('kid');
        $this->postJson($this->url, $this->input(['issuer' => 'other', 'kid' => 'second']))->assertUnprocessable()->assertJsonValidationErrors('issuer');
        $other = Platform::factory()->create();
        $otherUrl = '/api/v1/admin/platforms/' . $other->id . '/keys';
        $this->postJson($otherUrl, $this->input())->assertUnprocessable()->assertJsonValidationErrors('issuer');
        $this->assertSame(1, PlatformJwtIssuer::count());
        $this->assertSame(1, PlatformJwtKey::count());
        $this->assertSame(1, AuditLog::count());
        $this->postJson($otherUrl, $this->input(['issuer' => 'platform-b', 'public_key' => $this->differentPublic()]))->assertCreated();
        $this->postJson($this->url, $this->input(['kid' => 'Key-001', 'public_key' => $this->differentPublic()]))->assertCreated();
    }

    #[DataProvider('invalidInputs')]
    public function test_registration_validation(array $input): void
    {
        $this->authenticate();
        $this->postJson($this->url, $this->input($input))->assertUnprocessable();
        $this->assertSame(0, PlatformJwtKey::count());
        $this->assertSame(0, PlatformJwtIssuer::count());
        $this->assertSame(0, AuditLog::count());
    }

    public static function invalidInputs(): array
    {
        return array_map(fn ($input) => [$input], [
            ['issuer' => ''], ['issuer' => 'https://issuer'], ['kid' => str_repeat('a', 101)], ['kid' => []],
            ['public_key' => 'invalid'], ['public_key' => '-----BEGIN PRIVATE KEY-----secret-----END PRIVATE KEY-----'],
            ['is_active' => 'false'], ['valid_from' => '2026-02-30T00:00:00Z'],
            ['valid_until' => '2026-11-10T00:00:00+00:00'], ['valid_from' => '0999-01-01T00:00:00Z'],
            ['valid_until' => '1000-01-01T00:00:00Z'], ['platform_id' => 123], ['revoked_at' => null], ['permission' => 'anything'],
        ]);
    }

    public function test_rejects_weak_rsa_and_ec_keys(): void
    {
        $this->authenticate();
        foreach ([['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]] as $options) {
            $pair = openssl_pkey_new($options);
            $this->postJson($this->url, $this->input(['public_key' => openssl_pkey_get_details($pair)['key']]))
                ->assertUnprocessable()->assertJsonValidationErrors('public_key');
        }
    }

    #[DataProvider('operations')]
    public function test_authentication_permission_and_target_precedence(string $operation, string $permission): void
    {
        $this->authenticate();
        $id = $this->register();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->perform($operation, $id)->assertUnauthorized();
        $this->withToken('invalid-token');
        $this->app['auth']->forgetGuards();
        $this->perform($operation, $id)->assertUnauthorized();
        $admin = Admin::factory()->create();
        $revoked = $admin->createToken('revoked');
        $revoked->accessToken->delete();
        $this->withToken($revoked->plainTextToken);
        $this->app['auth']->forgetGuards();
        $this->perform($operation, $id)->assertUnauthorized();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'admin');
        $this->perform($operation, $id)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->authenticate(['platforms.manage']);
        $this->app['auth']->forgetGuards();
        $this->perform($operation, $id)->assertForbidden();
        $this->authenticate([$permission], false);
        $this->app['auth']->forgetGuards();
        $this->perform($operation, $id)->assertForbidden();
        $this->authenticate([$permission]);
        $this->app['auth']->forgetGuards();
        $this->perform($operation, $id)->assertSuccessful();
        $this->url = '/api/v1/admin/platforms/18446744073709551616/keys';
        $this->perform($operation, $id)->assertNotFound()->assertExactJson(['message' => 'Platform not found.']);
    }

    public static function operations(): array
    {
        return [['register', 'platforms.keys.register'], ['view', 'platforms.keys.view'], ['detail', 'platforms.keys.view'],
            ['status', 'platforms.keys.manage'], ['revoke', 'platforms.keys.revoke']];
    }

    private function perform(string $operation, string $id): \Illuminate\Testing\TestResponse
    {
        return match ($operation) {
            'register' => $this->postJson($this->url, $this->input(['kid' => 'another', 'public_key' => $this->differentPublic()])),
            'view' => $this->getJson($this->url),
            'detail' => $this->getJson($this->url . '/' . $id),
            'status' => $this->patchJson($this->url . '/' . $id . '/status', ['is_active' => false]),
            'revoke' => $this->postJson($this->url . '/' . $id . '/revoke'),
        };
    }

    public function test_nested_key_lookup_and_mutation_allowlists(): void
    {
        $this->authenticate();
        $id = $this->register();
        $this->patchJson($this->url . '/999999/status', [])->assertNotFound()->assertExactJson(['message' => 'Platform key not found.']);
        $other = Platform::factory()->create();
        $this->postJson('/api/v1/admin/platforms/' . $other->id . '/keys/' . $id . '/revoke')->assertNotFound();
        $this->patchJson($this->url . '/' . $id . '/status', ['is_active' => true, 'public_key' => 'x'])->assertUnprocessable();
        $this->patchJson($this->url . '/' . $id . '/status?is_active=1', ['is_active' => false])->assertUnprocessable();
        $this->postJson($this->url . '/' . $id . '/revoke', ['kid' => 'x'])->assertUnprocessable();
        $this->postJson($this->url . '?kid=query', $this->input(['kid' => 'second']))->assertUnprocessable();
        $this->getJson($this->url . '?sort=id')->assertUnprocessable();
        $this->assertTrue(PlatformJwtKey::findOrFail($id)->is_active);
        $this->assertSame(1, AuditLog::count());
    }

    #[DataProvider('writeOperations')]
    public function test_audit_failure_rolls_back_all_business_writes(string $operation): void
    {
        $this->authenticate();
        $id = $operation === 'register' ? '0' : $this->register();
        $count = PlatformJwtKey::count();
        $logs = AuditLog::count();
        $before = $operation === 'register' ? null : PlatformJwtKey::findOrFail($id)->getAttributes();
        $this->mock(AuditLogService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Test audit failure'));
        $this->withoutExceptionHandling();
        try {
            $this->perform($operation, $id);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Test audit failure', $exception->getMessage());
        }
        $this->assertSame($count, PlatformJwtKey::count());
        $this->assertSame($logs, AuditLog::count());
        if ($before !== null) {
            $this->assertSame($before, PlatformJwtKey::findOrFail($id)->getAttributes());
        } else {
            $this->assertSame(0, PlatformJwtIssuer::count());
        }
    }

    public static function writeOperations(): array
    {
        return [['register'], ['status'], ['revoke']];
    }

    #[DataProvider('writeOperations')]
    public function test_business_and_audit_use_same_connection_and_transaction(string $operation): void
    {
        $this->authenticate();
        $id = $operation === 'register' ? '0' : $this->register();
        $connection = DB::connection();
        $level = $connection->transactionLevel();
        $seen = [];
        $connection->beforeExecuting(function (string $sql, array $bindings, $actual) use ($connection, $level, &$seen): void {
            if (str_starts_with($sql, 'insert into `audit_logs`') || str_starts_with($sql, 'insert into `platform_jwt_')
                || str_starts_with($sql, 'update `platform_jwt_keys`') || str_ends_with($sql, 'for update')) {
                $this->assertSame($connection, $actual);
                $this->assertSame($level + 1, $actual->transactionLevel());
                $seen[] = $sql;
            }
        });
        $this->perform($operation, $id)->assertSuccessful();
        $this->assertTrue(count(array_filter($seen, fn ($sql) => str_contains($sql, 'audit_logs'))) === 1);
        $this->assertTrue(count(array_filter($seen, fn ($sql) => str_contains($sql, 'for update'))) >= 1);
    }

    #[DataProvider('writeOperations')]
    public function test_audit_sql_conflicts_propagate_without_becoming_business_validation(string $operation): void
    {
        $this->authenticate();
        $id = $operation === 'register' ? '0' : $this->register();
        $before = $operation === 'register' ? null : PlatformJwtKey::findOrFail($id)->getAttributes();
        $count = AuditLog::count();
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (str_starts_with($sql, 'insert into `audit_logs`')) {
                $previous = new \PDOException('Audit SQL failure');
                $previous->errorInfo = ['23000', 1062, "Duplicate entry for key 'platform_jwt_keys_platform_id_kid_unique'"];
                throw new QueryException('mysql', $sql, [], $previous);
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->perform($operation, $id);
            $this->fail('Expected SQL failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Audit SQL failure', $exception->getMessage());
        }
        $this->assertSame($count, AuditLog::count());
        if ($before !== null) {
            $this->assertSame($before, PlatformJwtKey::findOrFail($id)->getAttributes());
        } else {
            $this->assertSame(0, PlatformJwtIssuer::count());
            $this->assertSame(0, PlatformJwtKey::count());
        }
    }

    public function test_key_metadata_audit_allowlists_match_and_exclude_material_and_invalid_types(): void
    {
        $fields = ['issuer', 'kid', 'fingerprint', 'valid_from', 'valid_until', 'revoked_at', 'permission', 'ticket'];
        foreach (['safe', null, true, 42, 1.5, ['nested' => 'secret'], (object) ['nested' => 'secret']] as $value) {
            $values = array_fill_keys($fields, $value);
            $values['public_key'] = 'sensitive';
            $values['private_key'] = 'sensitive';
            $values['jwt'] = 'sensitive';
            $log = app(AuditLogService::class)->record(null, 'platform.key_registered', 'platform_key', newValues: $values)->fresh();
            $expected = is_string($value) || $value === null ? array_fill_keys($fields, $value) : [];
            $this->assertEquals($expected, $log->new_values);
            $rendered = (new \App\Http\Resources\Api\V1\Admin\AuditLogResource($log))->resolve();
            $this->assertEquals($expected, $rendered['new_values']);
            // Exercise the Resource independently of the service's write filtering.
            $log->new_values = $values;
            $this->assertEquals($expected, (new \App\Http\Resources\Api\V1\Admin\AuditLogResource($log))->resolve()['new_values']);
        }
    }

    #[DataProvider('duplicateKeys')]
    public function test_canonical_public_key_is_globally_unique_even_when_disabled_or_revoked(bool $otherPlatform, string $state, bool $rewrap): void
    {
        $this->authenticate();
        $id = $this->register();
        if ($state === 'revoked') {
            $this->postJson($this->url . '/' . $id . '/revoke')->assertOk();
        } elseif ($state === 'disabled') {
            $this->patchJson($this->url . '/' . $id . '/status', ['is_active' => false])->assertOk();
        }
        $logs = AuditLog::count();
        $url = $this->url;
        if ($otherPlatform) {
            $other = Platform::factory()->create();
            $url = '/api/v1/admin/platforms/' . $other->id . '/keys';
        }
        $pem = self::$public;
        if ($rewrap) {
            $base64 = preg_replace('/-----[^-]+-----|\s+/', '', $pem);
            $pem = "-----BEGIN PUBLIC KEY-----\r\n" . chunk_split($base64, 48, "\r\n") . "-----END PUBLIC KEY-----\r\n";
            $this->assertNotSame(self::$public, $pem);
        }
        $this->assertSame(RsaPublicKey::fromPem(self::$public)->fingerprint, RsaPublicKey::fromPem($pem)->fingerprint);
        $this->postJson($url, $this->input(['issuer' => $otherPlatform ? 'platform-b' : 'platform-a', 'kid' => 'different-kid', 'public_key' => $pem]))
            ->assertUnprocessable()->assertExactJson([
                'message' => 'The public key has already been registered.',
                'errors' => ['public_key' => ['The public key has already been registered.']],
            ]);
        $this->assertSame(1, PlatformJwtKey::count());
        $this->assertSame(1, PlatformJwtIssuer::count());
        $this->assertSame($logs, AuditLog::count());
    }

    public static function duplicateKeys(): array
    {
        return [[true, 'active', false], [false, 'active', false], [true, 'active', true], [false, 'active', true],
            [true, 'revoked', false], [false, 'revoked', true], [true, 'disabled', true]];
    }

    public function test_global_index_rejects_duplicate_fingerprint_with_different_kid_and_platform(): void
    {
        $this->authenticate();
        $id = $this->register();
        $other = Platform::factory()->create();
        PlatformJwtIssuer::create(['platform_id' => $other->id, 'issuer' => 'platform-b']);
        foreach ([$this->platform->id, $other->id] as $platformId) {
            $copy = PlatformJwtKey::findOrFail($id)->replicate();
            $copy->platform_id = $platformId;
            $copy->kid = 'second-kid';
            try {
                $copy->save();
                $this->fail('Expected global fingerprint conflict.');
            } catch (QueryException $exception) {
                $this->assertSame(1062, (int) $exception->errorInfo[1]);
                $this->assertStringContainsString('platform_jwt_keys_fingerprint_unique', $exception->errorInfo[2]);
            }
        }
        $this->assertSame(1, PlatformJwtKey::count());
    }

    public function test_fingerprint_unique_errors_from_audit_are_not_business_conflicts(): void
    {
        $this->authenticate();
        $this->withoutExceptionHandling();
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (str_starts_with($sql, 'insert into `audit_logs`')) {
                $previous = new \PDOException('Audit fingerprint SQL failure');
                $previous->errorInfo = ['23000', 1062, "Duplicate entry for key 'platform_jwt_keys_fingerprint_unique'"];
                throw new QueryException('mysql', $sql, [], $previous);
            }
        });
        try {
            $this->register();
            $this->fail('Expected audit SQL failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Audit fingerprint SQL failure', $exception->getMessage());
        }
        $this->assertSame(0, PlatformJwtKey::count());
        $this->assertSame(0, PlatformJwtIssuer::count());
    }

    public function test_schema_enforces_issuer_and_per_platform_kid_uniqueness(): void
    {
        $this->authenticate();
        $id = $this->register();
        try {
            PlatformJwtKey::findOrFail($id)->replicate()->save();
            $this->fail('Expected duplicate key.');
        } catch (QueryException $e) {
            $this->assertSame(1062, (int) $e->errorInfo[1]);
        }
        $other = Platform::factory()->create();
        try {
            PlatformJwtIssuer::create(['platform_id' => $other->id, 'issuer' => 'platform-a']);
            $this->fail('Expected duplicate issuer.');
        } catch (QueryException $e) {
            $this->assertSame(1062, (int) $e->errorInfo[1]);
        }
    }
}
