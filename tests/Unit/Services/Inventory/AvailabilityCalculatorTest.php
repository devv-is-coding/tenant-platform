<?php

use App\Services\Inventory\AvailabilityCalculator;
use App\ValueObjects\DateRange;
use Carbon\CarbonImmutable;

/**
 * A batch of the given size, effective between two dates (both included; null
 * for an open end).
 *
 * @return array{quantity: int, effective_from: CarbonImmutable|null, effective_to: CarbonImmutable|null}
 */
function stockBatch(int $quantity, ?string $from = null, ?string $to = null): array
{
    return [
        'quantity' => $quantity,
        'effective_from' => $from === null ? null : CarbonImmutable::parse($from),
        'effective_to' => $to === null ? null : CarbonImmutable::parse($to),
    ];
}

/**
 * The units an existing stay holds, night by night, as allocation rows would.
 *
 * @return array<string, int>
 */
function heldDuring(string $startsOn, string $endsOn, int $units = 1): array
{
    $held = [];

    foreach (DateRange::of($startsOn, $endsOn)->eachNight() as $night) {
        $held[$night->toDateString()] = $units;
    }

    return $held;
}

test('a stay of no nights is refused', function () {
    $calculator = new AvailabilityCalculator([stockBatch(3)], []);

    expect(fn () => $calculator->availableFor(DateRange::of('2026-12-05', '2026-12-05')))
        ->toThrow(InvalidArgumentException::class, 'A stay from 2026-12-05 to 2026-12-05 has no nights.');
});

test('an inverted stay cannot even be asked about', function () {
    expect(fn () => DateRange::of('2026-12-08', '2026-12-05'))->toThrow(InvalidArgumentException::class);
});

test('a stay can be sold as many times as the fewest units left on any of its nights', function (array $held, string $startsOn, string $endsOn, int $available) {
    $calculator = new AvailabilityCalculator([stockBatch(3)], $held);

    expect($calculator->availableFor(DateRange::of($startsOn, $endsOn)))->toBe($available);
})->with([
    'one night, nothing held' => [[], '2026-12-05', '2026-12-06', 3],
    'several nights, nothing held' => [[], '2026-12-05', '2026-12-10', 3],
    'one night, one held' => [heldDuring('2026-12-05', '2026-12-06'), '2026-12-05', '2026-12-06', 2],
    'back-to-back: the earlier guest leaves the morning this one arrives' => [heldDuring('2026-12-01', '2026-12-05', 3), '2026-12-05', '2026-12-08', 3],
    'back-to-back: this guest leaves the morning the next one arrives' => [heldDuring('2026-12-08', '2026-12-12', 3), '2026-12-05', '2026-12-08', 3],
    'fully inside a held stay' => [heldDuring('2026-12-01', '2026-12-20'), '2026-12-05', '2026-12-08', 2],
    'covering a held stay' => [heldDuring('2026-12-06', '2026-12-07'), '2026-12-05', '2026-12-08', 2],
    'overlapping a held stay\'s last night' => [heldDuring('2026-12-01', '2026-12-06'), '2026-12-05', '2026-12-08', 2],
    'overlapping a held stay\'s first night' => [heldDuring('2026-12-07', '2026-12-12'), '2026-12-05', '2026-12-08', 2],
    'one interior night sold out' => [heldDuring('2026-12-06', '2026-12-07', 3), '2026-12-05', '2026-12-08', 0],
]);

test('a batch counts only on the nights its effective range covers, both ends included', function (string $night, int $capacity) {
    $calculator = new AvailabilityCalculator([stockBatch(2), stockBatch(3, '2026-12-01', '2026-12-31')], []);

    expect($calculator->capacityOn(CarbonImmutable::parse($night)))->toBe($capacity);
})->with([
    'the day before it starts' => ['2026-11-30', 2],
    'its first day' => ['2026-12-01', 5],
    'its last day' => ['2026-12-31', 5],
    'the day after it ends' => ['2027-01-01', 2],
]);

test('a stay crossing the end of a batch gets only what the batch\'s last-covered nights and the rest share', function () {
    $calculator = new AvailabilityCalculator([stockBatch(2), stockBatch(3, '2026-12-01', '2026-12-31')], []);

    expect($calculator->availableFor(DateRange::of('2026-12-30', '2027-01-03')))->toBe(2);
});

test('an open start or end leaves the batch unbounded on that side', function () {
    $calculator = new AvailabilityCalculator([stockBatch(4, to: '2026-12-31'), stockBatch(1, from: '2027-01-01')], []);

    expect($calculator->capacityOn(CarbonImmutable::parse('2020-01-01')))->toBe(4)
        ->and($calculator->capacityOn(CarbonImmutable::parse('2030-01-01')))->toBe(1);
});

test('a stay beyond every batch has nothing to sell', function () {
    $calculator = new AvailabilityCalculator([stockBatch(3, '2026-12-01', '2026-12-31')], []);

    expect($calculator->availableFor(DateRange::of('2027-02-01', '2027-02-03')))->toBe(0);
});

test('a negative correction reduces the nights it covers, and never takes a night below zero', function () {
    $calculator = new AvailabilityCalculator(
        [stockBatch(3), stockBatch(-2, '2026-12-05', '2026-12-06')],
        heldDuring('2026-12-05', '2026-12-06', 2),
    );

    expect($calculator->availableOn(CarbonImmutable::parse('2026-12-05')))->toBe(0)
        ->and($calculator->availableOn(CarbonImmutable::parse('2026-12-07')))->toBe(3);
});

test('the breakdown lists every night of the stay with its capacity, units held and units left', function () {
    $calculator = new AvailabilityCalculator([stockBatch(2), stockBatch(1, '2026-12-06', '2026-12-06')], heldDuring('2026-12-06', '2026-12-07', 2));

    $breakdown = $calculator->breakdown(DateRange::of('2026-12-05', '2026-12-08'));

    expect(array_map(fn (array $night): array => [...$night, 'night' => $night['night']->toDateString()], $breakdown))->toBe([
        ['night' => '2026-12-05', 'capacity' => 2, 'held' => 0, 'available' => 2],
        ['night' => '2026-12-06', 'capacity' => 3, 'held' => 2, 'available' => 1],
        ['night' => '2026-12-07', 'capacity' => 2, 'held' => 0, 'available' => 2],
    ]);
});
