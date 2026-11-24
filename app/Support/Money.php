<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function decimalToMinor(string|int|float $value): int
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('Invalid money value.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = (int) str_pad($fraction, 2, '0');
        $maximumWhole = (string) intdiv(PHP_INT_MAX - $fraction, 100);
        if (strlen($whole) > strlen($maximumWhole) || (strlen($whole) === strlen($maximumWhole) && strcmp($whole, $maximumWhole) > 0)) {
            throw new InvalidArgumentException('Money value exceeds the supported range.');
        }

        return ((int) $whole * 100) + $fraction;
    }
}
