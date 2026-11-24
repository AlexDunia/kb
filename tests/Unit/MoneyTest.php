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

it('allocates large amounts exactly without overflowing intermediate products', function () {
    expect(Money::proportional(49_499_999_505, 1_999_999_980, 49_999_999_500))->toBe(1_979_999_980)
        ->and(Money::proportional(24, 1, 25))->toBe(0)
        ->and(Money::proportional(24, 1, 1))->toBe(24);
});
