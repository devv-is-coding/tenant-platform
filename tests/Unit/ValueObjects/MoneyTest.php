<?php

/*
 * Strict types, so a float passed where minor units belong fails here exactly
 * as PHPStan flags it everywhere else.
 */
declare(strict_types=1);

use App\ValueObjects\Money;

test('an amount cannot be built from a float', function () {
    expect(fn () => Money::ofMinor(30.01, 'PHP'))->toThrow(TypeError::class);
});

test('a currency that is not a three-letter ISO code is refused', function (string $currency) {
    expect(fn () => Money::ofMinor(100, $currency))->toThrow(InvalidArgumentException::class);
})->with([
    'lowercase' => ['php'],
    'a name' => ['PESO'],
    'empty' => [''],
]);

test('each currency has its own number of minor digits, never a hardcoded hundred', function (string $currency, string $amount, int $minor, string $decimal) {
    $money = Money::parse($amount, $currency);

    expect($money->minor)->toBe($minor)
        ->and($money->toDecimal())->toBe($decimal);
})->with([
    'yen has none' => ['JPY', '1500', 1500, '1500'],
    'peso has two' => ['PHP', '15.75', 1575, '15.75'],
    'dinar has three' => ['BHD', '1.234', 1234, '1.234'],
]);

test('a yen amount with decimals is refused', function () {
    expect(fn () => Money::parse('1500.5', 'JPY'))->toThrow(InvalidArgumentException::class);
});

test('an amount formats with its currency\'s own decimals', function (Money $money, string $formatted) {
    expect($money->format('en'))->toBe($formatted);
})->with([
    'yen' => [Money::ofMinor(1500, 'JPY'), '¥1,500'],
    'peso' => [Money::ofMinor(250000, 'PHP'), '₱2,500.00'],
]);

test('adding and subtracting stay exact in minor units', function () {
    $total = Money::parse('30.01', 'PHP')->plus(Money::parse('0.02', 'PHP'));

    expect($total->minor)->toBe(3003)
        ->and($total->minus(Money::parse('30.03', 'PHP'))->minor)->toBe(0)
        ->and(Money::ofMinor(1999, 'PHP')->times(3)->minor)->toBe(5997);
});

test('combining amounts in different currencies is refused', function (Closure $combine) {
    expect(fn () => $combine(Money::ofMinor(100, 'PHP'), Money::ofMinor(100, 'JPY')))
        ->toThrow(InvalidArgumentException::class, 'Cannot combine PHP with JPY.');
})->with([
    'plus' => [fn (Money $peso, Money $yen) => $peso->plus($yen)],
    'minus' => [fn (Money $peso, Money $yen) => $peso->minus($yen)],
    'equals' => [fn (Money $peso, Money $yen) => $peso->equals($yen)],
]);

test('a percentage rounds half away from zero to the nearest minor unit', function (int $minor, int $percent, int $expected) {
    expect(Money::ofMinor($minor, 'PHP')->percentage($percent)->minor)->toBe($expected);
})->with([
    'exactly half rounds up' => [1001, 50, 501],
    'above half rounds up' => [1003, 30, 301],
    'below half rounds down' => [1001, 30, 300],
    'a negative half rounds away from zero' => [-1001, 50, -501],
    'none' => [1001, 0, 0],
    'all of it' => [1001, 100, 1001],
]);

test('a negative percentage is refused', function () {
    expect(fn () => Money::ofMinor(1000, 'PHP')->percentage(-10))->toThrow(InvalidArgumentException::class);
});

test('allocating hands out every minor unit, so the parts sum to the whole', function (int $minor, array $ratios, array $expected) {
    $parts = Money::ofMinor($minor, 'PHP')->allocate(...$ratios);

    expect(array_map(fn (Money $part): int => $part->minor, $parts))->toBe($expected)
        ->and(array_sum(array_map(fn (Money $part): int => $part->minor, $parts)))->toBe($minor)
        ->and(array_unique(array_map(fn (Money $part): string => $part->currency, $parts)))->toBe(['PHP']);
})->with([
    'a peso three ways' => [100, [1, 1, 1], [34, 33, 33]],
    'a 30/70 deposit split' => [1001, [30, 70], [300, 701]],
    'fewer units than parts' => [5, [1, 1, 1, 1, 1, 1, 1], [1, 1, 1, 1, 1, 0, 0]],
    'the largest remainder wins, not the first part' => [10, [1, 2], [3, 7]],
    'a zero ratio gets nothing' => [100, [0, 1], [0, 100]],
    'a refund, negative' => [-100, [1, 1, 1], [-34, -33, -33]],
    'nothing to split' => [0, [1, 2], [0, 0]],
]);

test('allocating by ratios that cannot split anything is refused', function (array $ratios) {
    expect(fn () => Money::ofMinor(100, 'PHP')->allocate(...$ratios))->toThrow(InvalidArgumentException::class);
})->with([
    'no ratios' => [[]],
    'all zero' => [[0, 0]],
    'a negative ratio' => [[-1, 2]],
]);

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
