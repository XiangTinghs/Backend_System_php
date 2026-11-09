<?php

namespace Tests\Feature\Auth;

use App\Auth\PlatformContext;
use App\Models\Platform;
use App\Models\PlatformJwtIssuer;
use App\Models\PlatformJwtKey;
use Firebase\JWT\JWT;
use App\Support\RsaPublicKey;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformJwtTest extends TestCase
{
    use DatabaseTransactions;

    private static string $private = '';
    private static string $public;
    private Platform $platform;
    private PlatformJwtKey $key;
    private const URL = '/api/test/platform-jwt';

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$private === '') {
            $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($pair, self::$private);
            self::$public = openssl_pkey_get_details($pair)['key'];
        }
        $this->travelTo(now('UTC')->startOfSecond());
        $this->platform = Platform::factory()->create();
        PlatformJwtIssuer::create(['platform_id' => $this->platform->id, 'issuer' => 'platform-a']);
        $this->key = PlatformJwtKey::create(['platform_id' => $this->platform->id, 'kid' => 'key-001',
            'public_key' => self::$public, 'fingerprint' => RsaPublicKey::fromPem(self::$public)->fingerprint, 'is_active' => true,
            'valid_from' => now()->subMinute(), 'valid_until' => now()->addDay()]);
        Route::post(self::URL, function (Request $request) {
            $context = $request->attributes->get(PlatformContext::class);
            return response()->json(['platform_id' => $context->platformId, 'issuer' => $context->issuer, 'kid' => $context->kid]);
        })->middleware('platform.jwt');
    }

    private function token(array $overrides = [], array $header = []): string
    {
        $claims = array_replace(['iss' => 'platform-a', 'sub' => 'platform:platform-a', 'aud' => 'game-provider-api',
            'iat' => now()->timestamp, 'nbf' => now()->timestamp, 'exp' => now()->timestamp + 300, 'jti' => 'reusable-id'], $overrides);
        $head = array_replace(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => 'key-001'], $header);
        $input = JWT::urlsafeB64Encode(JWT::jsonEncode($head)) . '.' . JWT::urlsafeB64Encode(JWT::jsonEncode($claims));

        return $input . '.' . JWT::urlsafeB64Encode(JWT::sign($input, self::$private, 'RS256'));
    }

    public function test_reuse_context_body_identity_and_exact_expiry_boundary(): void
    {
        $token = $this->token();
        $before = $this->key->refresh()->getAttributes();
        $this->withToken($token)->postJson(self::URL, ['platform_id' => '999'])
            ->assertExactJson(['platform_id' => (string) $this->platform->id, 'issuer' => 'platform-a', 'kid' => 'key-001']);
        $this->travel(299)->seconds();
        $this->postJson(self::URL)->assertOk();
        $this->travel(1)->seconds();
        $this->postJson(self::URL)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertSame($before, $this->key->refresh()->getAttributes());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('invalidClaims')]
    public function test_invalid_claims_are_rejected(array $claims): void
    {
        $now = now()->timestamp;
        foreach (['iat', 'nbf', 'exp'] as $field) {
            if (isset($claims[$field]) && is_string($claims[$field]) && str_starts_with($claims[$field], 'offset:')) {
                $claims[$field] = $now + (int) substr($claims[$field], 7);
            }
        }
        $this->withToken($this->token($claims))->postJson(self::URL)->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public static function invalidClaims(): array
    {
        return [
            'issuer' => [['iss' => 'unknown']], 'case sensitive issuer' => [['iss' => 'Platform-a']],
            'subject' => [['sub' => 'platform:other']], 'audience' => [['aud' => 'other']],
            'audience array' => [['aud' => ['game-provider-api']]],
            'too long' => [['exp' => 'offset:301']], 'expired' => [['iat' => 'offset:-300', 'nbf' => 'offset:-300', 'exp' => 'offset:-1']],
            'iat future with nbf present' => [['iat' => 'offset:31']], 'nbf future' => [['nbf' => 'offset:31']],
            'empty lifetime' => [['exp' => 'offset:0']], 'nbf after exp' => [['nbf' => 'offset:301']],
            'iat missing' => [['iat' => null]], 'nbf missing' => [['nbf' => null]], 'exp missing' => [['exp' => null]],
            'iss missing' => [['iss' => null]], 'aud missing' => [['aud' => null]], 'sub missing' => [['sub' => null]],
            'numeric string' => [['iat' => '123']], 'float' => [['iat' => 123.5]], 'boolean' => [['exp' => true]],
            'array' => [['nbf' => []]], 'negative' => [['iat' => -1]], 'jti type' => [['jti' => []]],
        ];
    }

    public function test_clock_skew_at_30_seconds_and_library_state_restoration(): void
    {
        $leeway = JWT::$leeway;
        $timestamp = JWT::$timestamp;
        JWT::$leeway = 123;
        JWT::$timestamp = 1;
        try {
            $this->withToken($this->token(['iat' => now()->timestamp + 30, 'nbf' => now()->timestamp + 30]))
                ->postJson(self::URL)->assertOk();
            $this->assertSame(123, JWT::$leeway);
            $this->assertSame(1, JWT::$timestamp);
            $this->withToken($this->token(['exp' => now()->timestamp - 1]))->postJson(self::URL)->assertUnauthorized();
            $this->assertSame(123, JWT::$leeway);
            $this->assertSame(1, JWT::$timestamp);
        } finally {
            JWT::$leeway = $leeway;
            JWT::$timestamp = $timestamp;
        }
    }

    #[DataProvider('invalidHeaders')]
    public function test_untrusted_headers_are_rejected(array $header): void
    {
        $this->withToken($this->token([], $header))->postJson(self::URL)->assertUnauthorized();
    }

    public static function invalidHeaders(): array
    {
        return array_map(fn ($header) => [$header], [['alg' => 'none'], ['alg' => 'HS256'], ['kid' => 'unknown'], ['kid' => 'KEY-001'], ['kid' => null],
            ['kid' => []], ['typ' => 'other'], ['jku' => 'https://untrusted.invalid/keys'], ['jwk' => []],
            ['x5u' => 'https://untrusted.invalid/key'], ['crit' => ['anything']]]);

    }

    public function test_tampered_signature_and_payload_and_other_rsa_key_are_rejected(): void
    {
        $token = $this->token();
        [$header, $body, $signature] = explode('.', $token);
        $signature[0] = $signature[0] === 'a' ? 'b' : 'a';
        $this->withToken("$header.$body.$signature")->postJson(self::URL)->assertUnauthorized();
        $payload = json_decode(JWT::urlsafeB64Decode($body), true);
        $payload['jti'] = 'tampered';
        $this->withToken($header . '.' . JWT::urlsafeB64Encode(json_encode($payload)) . '.' . explode('.', $token)[2])
            ->postJson(self::URL)->assertUnauthorized();
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->key->update(['public_key' => openssl_pkey_get_details($other)['key']]);
        $this->withToken($token)->postJson(self::URL)->assertUnauthorized();
    }

    #[DataProvider('liveStates')]
    public function test_every_request_rechecks_live_platform_and_key_state(string $change): void
    {
        $this->withToken($this->token())->postJson(self::URL)->assertOk();
        match ($change) {
            'platform' => $this->platform->update(['is_active' => false]),
            'disabled' => $this->key->update(['is_active' => false]),
            'revoked' => $this->key->forceFill(['revoked_at' => now()])->save(),
            'not yet valid' => $this->key->update(['valid_from' => now()->addSecond()]),
            'expired key' => $this->key->update(['valid_until' => now()]),
            'deleted' => $this->key->delete(),
        };
        $response = $this->postJson(self::URL);
        if ($change === 'platform') {
            $response->assertForbidden()->assertExactJson(['message' => 'Platform is disabled.']);
        } else {
            $response->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        }
    }

    public static function liveStates(): array
    {
        return [['platform'], ['disabled'], ['revoked'], ['not yet valid'], ['expired key'], ['deleted']];
    }

    #[DataProvider('malformedTokens')]
    public function test_missing_and_malformed_bearer_tokens(string $header): void
    {
        $this->withHeader('Authorization', $header)->postJson(self::URL)->assertUnauthorized();
    }

    public static function malformedTokens(): array
    {
        return [[''], ['Basic abc'], ['Bearer abc'], ['Bearer a.b.c'], ['Bearer e30.W10.AA'], ['Bearer a.b.c.d'], ['Bearer ' . str_repeat('x', 16385)]];
    }

    public function test_platform_token_cannot_access_admin_routes_and_admin_token_cannot_access_platform(): void
    {
        $jwt = $this->token();
        $this->withToken($jwt)->getJson('/api/v1/admin/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/platforms')->assertUnauthorized();
        $admin = \App\Models\Admin::factory()->create();
        $this->withToken($admin->createToken('test')->plainTextToken)->postJson(self::URL)->assertUnauthorized();
    }

    public function test_real_hmac_algorithm_confusion_and_unsigned_tokens_are_rejected(): void
    {
        $claims = ['iss' => 'platform-a', 'sub' => 'platform:platform-a', 'aud' => 'game-provider-api',
            'iat' => now()->timestamp, 'nbf' => now()->timestamp, 'exp' => now()->timestamp + 300];
        $this->withToken(JWT::encode($claims, self::$public, 'HS256', 'key-001'))->postJson(self::URL)->assertUnauthorized();
        $unsigned = JWT::urlsafeB64Encode(JWT::jsonEncode(['alg' => 'none', 'typ' => 'JWT', 'kid' => 'key-001']))
            . '.' . JWT::urlsafeB64Encode(JWT::jsonEncode($claims)) . '.';
        $this->withToken($unsigned)->postJson(self::URL)->assertUnauthorized();
    }

    public function test_unknown_platform_cannot_borrow_another_platforms_key_id(): void
    {
        $other = Platform::factory()->create();
        PlatformJwtIssuer::create(['platform_id' => $other->id, 'issuer' => 'platform-b']);
        $this->withToken($this->token(['iss' => 'platform-b', 'sub' => 'platform:platform-b']))
            ->postJson(self::URL)->assertUnauthorized();
    }

    public function test_same_kid_on_distinct_platform_keys_cannot_authenticate_a_forged_issuer(): void
    {
        $other = Platform::factory()->create();
        PlatformJwtIssuer::create(['platform_id' => $other->id, 'issuer' => 'platform-b']);
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $private = '';
        openssl_pkey_export($pair, $private);
        $public = RsaPublicKey::fromPem(openssl_pkey_get_details($pair)['key']);
        $otherKey = $this->key->replicate();
        $otherKey->platform_id = $other->id;
        $otherKey->public_key = $public->pem;
        $otherKey->fingerprint = $public->fingerprint;
        $otherKey->save();
        $forged = $this->token(['iss' => 'platform-b', 'sub' => 'platform:platform-b']);
        $this->withToken($forged)->postJson(self::URL)->assertUnauthorized();
        $claims = json_decode(JWT::urlsafeB64Decode(explode('.', $forged)[1]), true);
        $valid = JWT::encode($claims, $private, 'RS256', 'key-001');
        $this->withToken($valid)->postJson(self::URL)->assertOk()->assertJsonPath('platform_id', (string) $other->id);
        $this->withToken($this->token())->postJson(self::URL)->assertOk()->assertJsonPath('platform_id', (string) $this->platform->id);
    }

    public function test_rotation_allows_overlapping_keys_and_revocation_affects_only_one_key(): void
    {
        $old = $this->token();
        $new = $this->key->replicate();
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $private = '';
        openssl_pkey_export($pair, $private);
        $public = RsaPublicKey::fromPem(openssl_pkey_get_details($pair)['key']);
        $new->public_key = $public->pem;
        $new->fingerprint = $public->fingerprint;
        $new->kid = 'key-002';
        $new->save();
        $claims = json_decode(JWT::urlsafeB64Decode(explode('.', $old)[1]), true);
        $rotated = JWT::encode($claims, $private, 'RS256', 'key-002');
        $this->withToken($old)->postJson(self::URL)->assertOk();
        $this->withToken($rotated)->postJson(self::URL)->assertOk();
        $this->key->forceFill(['revoked_at' => now()])->save();
        $this->withToken($old)->postJson(self::URL)->assertUnauthorized();
        $this->withToken($rotated)->postJson(self::URL)->assertOk();
    }
}
