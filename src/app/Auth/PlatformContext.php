<?php

namespace App\Auth;

final readonly class PlatformContext
{
    public function __construct(
        public string $platformId,
        public string $issuer,
        public string $keyId,
        public string $kid,
        public int $expiresAt,
    ) {
    }
}
