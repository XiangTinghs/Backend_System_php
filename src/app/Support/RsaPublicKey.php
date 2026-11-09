<?php

namespace App\Support;

use InvalidArgumentException;

final readonly class RsaPublicKey
{
    private function __construct(public string $pem, public string $fingerprint)
    {
    }

    public static function fromPem(string $pem): self
    {
        if (strlen($pem) > 8192 || preg_match('/\A-----BEGIN PUBLIC KEY-----\s+[A-Za-z0-9+\/=\s]+-----END PUBLIC KEY-----\s*\z/', $pem) !== 1) {
            throw new InvalidArgumentException('Supply a PEM RSA public key.');
        }
        $key = @openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048 || $details['bits'] > 8192) {
            throw new InvalidArgumentException('Supply a 2048–8192 bit RSA public key.');
        }
        // OpenSSL exports canonical SubjectPublicKeyInfo, independent of incoming PEM wrapping.
        $canonical = $details['key'];
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $canonical), true);
        if ($der === false) {
            throw new InvalidArgumentException('Supply a PEM RSA public key.');
        }

        return new self($canonical, hash('sha256', $der));
    }
}
