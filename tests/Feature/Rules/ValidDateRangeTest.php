<?php

use App\Rules\ValidDateRange;
use Illuminate\Support\Facades\Validator;

/**
 * Validate a start and end date the way a booking or itinerary form will.
 *
 * @param  array<string, mixed>  $input
 * @return array<string, list<string>>
 */
function dateRangeErrors(array $input): array
{
    return Validator::make($input, [
        'starts_on' => ['required', 'date_format:Y-m-d'],
        'ends_on' => ['required', new ValidDateRange('starts_on')],
    ])->errors()->toArray();
}

test('an end date on or after the start date passes', function (string $endsOn) {
    expect(dateRangeErrors(['starts_on' => '2026-03-01', 'ends_on' => $endsOn]))->toBe([]);
})->with([
    'later' => ['2026-03-05'],
    'the same day' => ['2026-03-01'],
]);

test('an end date before the start date is refused with a message', function () {
    expect(dateRangeErrors(['starts_on' => '2026-06-01', 'ends_on' => '0026-06-05']))
        ->toBe(['ends_on' => ['The ends on cannot be before the start date.']]);
});

test('an end date that is not a real Y-m-d date is refused with a message', function (mixed $endsOn) {
    expect(dateRangeErrors(['starts_on' => '2026-03-01', 'ends_on' => $endsOn]))
        ->toBe(['ends_on' => ['The ends on must be a date in the Y-m-d format.']]);
})->with([
    'a day that rolls over' => ['2026-02-30'],
    'another format' => ['03/05/2026'],
    'not a string' => [20260305],
]);

test('a bad start date is reported once, on the start date, not again on the end date', function () {
    expect(dateRangeErrors(['starts_on' => 'soon', 'ends_on' => '2026-03-05']))
        ->toBe(['starts_on' => ['The starts on field must match the format Y-m-d.']]);
});
