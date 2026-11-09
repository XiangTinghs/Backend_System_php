<?php

namespace App\Services;

use App\Auth\PlatformContext;
use App\Exceptions\PlatformDisabledException;
use App\Models\PlatformJwtKey;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use stdClass;
use UnexpectedValueException;

class PlatformJwtVerifier
{
    public function verify(#[\SensitiveParameter] string $token): PlatformContext
    {
        if (strlen($token) > 16384 || preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/', $token) !== 1) {
            throw new UnexpectedValueException('Invalid JWT.');
        }
        [$header, $payload] = array_map(
            fn (string $part) => JWT::jsonDecode(JWT::urlsafeB64Decode($part)),
            array_slice(explode('.', $token), 0, 2)
        );
        if (
            ! $header instanceof stdClass || ! $payload instanceof stdClass
            || ($header->alg ?? null) !== 'RS256' || ($header->typ ?? null) !== 'JWT'
            || ! is_string($header->kid ?? null) || ! $this->validCode($header->kid)
            || array_diff(array_keys((array) $header), ['alg', 'typ', 'kid']) !== []
            || ! is_string($payload->iss ?? null) || ! $this->validCode($payload->iss)
        ) {
            throw new UnexpectedValueException('Invalid JWT.');
        }

        // Unverified iss/kid only select a registered key; they never establish identity.
        $key = PlatformJwtKey::query()
            ->where('kid', $header->kid)
            ->whereHas('issuer', fn ($query) => $query->where('issuer', $payload->iss))
            ->with(['issuer', 'platform'])->first();
        $now = now('UTC')->getTimestamp();
        if (
            $key === null || ! $key->is_active || $key->revoked_at !== null
            || $key->valid_from->getTimestamp() > $now || $key->valid_until->getTimestamp() <= $now
            || $key->platform === null
        ) {
            throw new UnexpectedValueException('Invalid JWT.');
        }

        // The library has one shared leeway for all dates. Always restore its process state,
        // then impose strict exp and independently validate iat even when nbf is present.
        $leeway = JWT::$leeway;
        $timestamp = JWT::$timestamp;
        try {
            JWT::$leeway = 30;
            JWT::$timestamp = $now;
            $claims = JWT::decode($token, new Key($key->public_key, 'RS256'));
        } finally {
            JWT::$leeway = $leeway;
            JWT::$timestamp = $timestamp;
        }
        foreach (['iat', 'nbf', 'exp'] as $field) {
            if (! is_int($claims->{$field} ?? null) || $claims->{$field} < 0) {
                throw new UnexpectedValueException('Invalid JWT.');
            }
        }
        if (
            $claims->iss !== $key->issuer->issuer
            || ($claims->sub ?? null) !== 'platform:' . $key->issuer->issuer
            || ($claims->aud ?? null) !== 'game-provider-api'
            || $claims->exp <= $now || $claims->exp <= $claims->iat
            || $claims->exp - $claims->iat > 300 || $claims->nbf >= $claims->exp
            || $claims->iat > $now + 30 || $claims->nbf > $now + 30
            || (isset($claims->jti) && (! is_string($claims->jti) || $claims->jti === '' || strlen($claims->jti) > 128))
        ) {
            throw new UnexpectedValueException('Invalid JWT.');
        }

        if (! $key->platform->is_active) {
            throw new PlatformDisabledException('Platform is disabled.');
        }

        return new PlatformContext((string) $key->platform_id, $key->issuer->issuer, (string) $key->id, $key->kid, $claims->exp);
    }

    private function validCode(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/', $value) === 1;
    }
}
