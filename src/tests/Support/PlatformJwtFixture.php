<?php

namespace Tests\Support;

use App\Models\Platform;
use App\Models\PlatformJwtIssuer;
use App\Models\PlatformJwtKey;
use App\Support\RsaPublicKey;
use Firebase\JWT\JWT;

final class PlatformJwtFixture
{
    private static array $pairs = [];

    public static function create(int $slot = 0): array
    {
        if (! isset(self::$pairs[$slot])) {
            $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $private = '';
            openssl_pkey_export($pair, $private);
            self::$pairs[$slot] = ['private' => $private, 'public' => RsaPublicKey::fromPem(openssl_pkey_get_details($pair)['key'])];
        }
        $pair = self::$pairs[$slot];
        $platform = Platform::factory()->create();
        $issuer = 'resolve-' . bin2hex(random_bytes(8));
        PlatformJwtIssuer::create(['platform_id' => $platform->id, 'issuer' => $issuer]);
        $key = PlatformJwtKey::create(['platform_id' => $platform->id, 'kid' => 'resolve-key',
            'public_key' => $pair['public']->pem, 'fingerprint' => $pair['public']->fingerprint, 'is_active' => true,
            'valid_from' => now('UTC')->subMinute(), 'valid_until' => now('UTC')->addDays(7)]);
        return ['platform' => $platform, 'key' => $key, 'issuer' => $issuer, 'private' => $pair['private']];
    }

    public static function token(array $fixture, array $overrides = []): string
    {
        return JWT::encode(
            array_replace(['iss' => $fixture['issuer'], 'sub' => 'platform:' . $fixture['issuer'],
            'aud' => 'game-provider-api', 'iat' => now('UTC')->timestamp, 'nbf' => now('UTC')->timestamp,
            'exp' => now('UTC')->timestamp + 300, 'jti' => 'reusable-resolve-jwt'], $overrides),
            $fixture['private'],
            'RS256',
            'resolve-key'
        );
    }
}
