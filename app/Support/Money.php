<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    /** Exact floor(amount * weight / total), without overflowing the intermediate product. */
    public static function proportional(int $amount, int $weight, int $total): int
    {
        if ($amount < 0 || $weight < 0 || $total <= 0 || $weight > $total || $total > intdiv(PHP_INT_MAX, 2)) {
            throw new InvalidArgumentException('Invalid allocation weights.');
        }
        $whole = intdiv($amount, $total);
        $remainder = $amount % $total;
        $result = 0;
        $carry = 0;
        while ($weight > 0) {
            if ($weight % 2) {
                $result += $whole;
                $carry += $remainder;
                if ($carry >= $total) {
                    $result++;
                    $carry -= $total;
                }
            }
            $weight = intdiv($weight, 2);
            if ($weight) {
                $whole *= 2;
                $remainder *= 2;
                if ($remainder >= $total) {
                    $whole++;
                    $remainder -= $total;
                }
            }
        }

        return $result;
    }

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
