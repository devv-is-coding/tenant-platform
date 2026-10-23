<?php

use App\ValueObjects\DateRange;
use Carbon\CarbonImmutable;

test('the check-out day is not a night', function (string $startsOn, string $endsOn, int $nights) {
    expect(DateRange::of($startsOn, $endsOn)->nights())->toBe($nights);
})->with([
    'four nights' => ['2026-03-01', '2026-03-05', 4],
    'one night' => ['2026-03-01', '2026-03-02', 1],
    'across a month end' => ['2026-02-27', '2026-03-02', 3],
    'leaving the day of arrival' => ['2026-03-01', '2026-03-01', 0],
]);

test('each night runs from the arrival date to the night before check-out', function () {
    $nights = DateRange::of('2026-03-30', '2026-04-02')->eachNight();

    expect(array_map(fn (CarbonImmutable $night): string => $night->toDateString(), $nights))
        ->toBe(['2026-03-30', '2026-03-31', '2026-04-01']);
});

test('a stay with no nights lists none', function () {
    expect(DateRange::of('2026-03-01', '2026-03-01')->eachNight())->toBe([]);
});

test('a range that ends before it starts cannot be built', function (string $startsOn, string $endsOn) {
    expect(fn () => DateRange::of($startsOn, $endsOn))->toThrow(InvalidArgumentException::class);
})->with([
    'a day early' => ['2026-03-05', '2026-03-04'],
    'the legacy tour ending in the year 0026 (defect 3)' => ['2026-06-01', '0026-06-05'],
]);

test('a string that is not a real Y-m-d date is refused', function (string $date) {
    expect(fn () => DateRange::of($date, '2026-12-31'))->toThrow(InvalidArgumentException::class);
})->with([
    'a day that rolls over' => ['2026-02-30'],
    'another format' => ['03/01/2026'],
    'with a time' => ['2026-03-01 10:00:00'],
    'empty' => [''],
]);

test('a time of day is dropped, so only calendar dates count', function () {
    $range = DateRange::of(CarbonImmutable::parse('2026-03-01 23:00'), CarbonImmutable::parse('2026-03-02 01:00'));

    expect($range->nights())->toBe(1)
        ->and($range->startsOn->toDateTimeString())->toBe('2026-03-01 00:00:00');
});

test('two stays overlap only when they share a night, in either order', function (array $first, array $second, bool $overlaps) {
    $firstStay = DateRange::of(...$first);
    $secondStay = DateRange::of(...$second);

    expect($firstStay->overlaps($secondStay))->toBe($overlaps)
        ->and($secondStay->overlaps($firstStay))->toBe($overlaps);
})->with([
    'back to back: one leaves the day the other arrives' => [['2026-03-01', '2026-03-05'], ['2026-03-05', '2026-03-08'], false],
    'sharing the last night' => [['2026-03-01', '2026-03-05'], ['2026-03-04', '2026-03-08'], true],
    'one inside the other' => [['2026-03-01', '2026-03-10'], ['2026-03-04', '2026-03-05'], true],
    'the same stay' => [['2026-03-01', '2026-03-05'], ['2026-03-01', '2026-03-05'], true],
    'apart' => [['2026-03-01', '2026-03-03'], ['2026-03-07', '2026-03-09'], false],
    'an empty stay inside another' => [['2026-03-01', '2026-03-05'], ['2026-03-03', '2026-03-03'], false],
]);
