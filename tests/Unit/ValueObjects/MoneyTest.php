<?php

use App\ValueObjects\Money;

test('a typed amount becomes exact minor units', function (string $amount, int $minor) {
    expect(Money::parse($amount, 'PHP')->minor)->toBe($minor);
})->with([
    'whole' => ['2500', 250000],
    'two decimal places' => ['2500.00', 250000],
    'one decimal place' => ['0.5', 50],
    'a cent float arithmetic corrupts' => ['30.01', 3001],
    'leading zeros' => ['007.10', 710],
    'zero' => ['0', 0],
]);

test('an amount that is not digits with at most two decimal places is refused', function (string $amount) {
    expect(fn () => Money::parse($amount, 'PHP'))->toThrow(InvalidArgumentException::class);
})->with([
    'three decimal places' => ['1.234'],
    'negative' => ['-1'],
    'letters' => ['abc'],
    'empty' => [''],
    'trailing dot' => ['12.'],
    'thousands separator' => ['2,500'],
    'scientific notation' => ['1e3'],
    'wider than an integer' => ['99999999999999999999'],
]);

test('minor units read back as a decimal string in major units', function (int $minor, string $decimal) {
    expect(Money::ofMinor($minor, 'PHP')->toDecimal())->toBe($decimal);
})->with([
    [250000, '2500.00'],
    [3001, '30.01'],
    [5, '0.05'],
    [0, '0.00'],
    [-150, '-1.50'],
]);
