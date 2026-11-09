<?php

namespace App\Support;

final class UnsignedBigInt
{
    public static function valid(mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        $value = (string) $value;

        return preg_match('/\A[1-9][0-9]*\z/', $value) === 1
            && (strlen($value) < 20 || (strlen($value) === 20 && strcmp($value, '18446744073709551615') <= 0));
    }

    public static function jsonValue(string|int $value): string|int
    {
        $value = (string) $value;
        $max = (string) PHP_INT_MAX;

        return strlen($value) < strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) <= 0)
            ? (int) $value : $value;
    }
}
