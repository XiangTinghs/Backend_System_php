<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\RsaPublicKey;
use Closure;
use InvalidArgumentException;
use DateTimeImmutable;
use DateTimeZone;

class StorePlatformKeyRequest extends PlatformKeyRequest
{
    public function rules(): array
    {
        $date = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $value) !== 1) {
                $fail('Use a UTC date in YYYY-MM-DDTHH:MM:SSZ format.');
                return;
            }
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
            if ($parsed === false || $parsed->format('Y-m-d\TH:i:s\Z') !== $value) {
                $fail('The date must be a real UTC date.');
            }
        };

        return [
            'issuer' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/'],
            'kid' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/'],
            'public_key' => ['required', 'string', 'max:8192', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    $fail('Supply a PEM RSA public key.');
                    return;
                }
                try {
                    RsaPublicKey::fromPem($value);
                } catch (InvalidArgumentException $exception) {
                    $fail($exception->getMessage());
                }
            }],
            'is_active' => ['sometimes', 'boolean'],
            'valid_from' => ['required', 'string', $date],
            'valid_until' => ['required', 'string', $date, 'after:valid_from'],
        ];
    }
}
