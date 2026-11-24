<?php

use App\Support\Money;

it('converts decimal currency without floating point arithmetic', function () {
    expect(Money::decimalToMinor('0.00'))->toBe(0)
        ->and(Money::decimalToMinor('00012.05'))->toBe(1205)
        ->and(Money::decimalToMinor('12.5'))->toBe(1250);
});

it('rejects excess precision negative values and integer overflow', function (string $value) {
    expect(fn () => Money::decimalToMinor($value))->toThrow(InvalidArgumentException::class);
})->with(['1.999', '-1', '1e5', '999999999999999999999999']);
