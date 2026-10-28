<?php

use App\Models\Item;
use App\Models\ItineraryStop;
use Illuminate\Database\UniqueConstraintViolationException;

test('a stop whose stay ends before it starts is refused; one without dates is allowed', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        expect(fn () => ItineraryStop::factory()->create(['starts_on' => '2026-12-05', 'ends_on' => '2026-12-01']))
            ->toThrow(InvalidArgumentException::class, 'A range cannot end (2026-12-01) before it starts (2026-12-05).');

        ItineraryStop::factory()->create(['starts_on' => null, 'ends_on' => null]);

        expect(ItineraryStop::count())->toBe(1);
    });
});

test('coordinates off the globe are refused; a stop without them is allowed', function (array $coordinates, ?string $message) {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function () use ($coordinates, $message): void {
        $create = fn () => ItineraryStop::factory()->create($coordinates);

        if ($message === null) {
            expect($create()->exists)->toBeTrue();
        } else {
            expect($create)->toThrow(InvalidArgumentException::class, $message);
        }
    });
})->with([
    'latitude past the pole' => [['latitude' => '90.500000'], 'Latitude 90.500000 is not between -90 and 90.'],
    'longitude past the date line' => [['longitude' => '-180.100000'], 'Longitude -180.100000 is not between -180 and 180.'],
    'no coordinates' => [['latitude' => null, 'longitude' => null], null],
]);

test('coordinates are stored exactly, to six decimal places', function () {
    $tenant = tenantOnDomain();

    $stop = withinTenant($tenant, fn () => ItineraryStop::query()->findOrFail(ItineraryStop::factory()->create(['latitude' => '43.061800', 'longitude' => '141.354500'])->id)->only(['latitude', 'longitude']));

    expect($stop)->toBe(['latitude' => '43.061800', 'longitude' => '141.354500']);
});

test('two stops cannot share a place in the route, and a deleted stop keeps its place, so the next stop goes after it', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        $item = Item::factory()->create();
        ItineraryStop::factory()->for($item)->create(['sequence' => 1]);
        ItineraryStop::factory()->for($item)->create(['sequence' => 2])->delete();

        expect(fn () => ItineraryStop::factory()->for($item)->create(['sequence' => 1]))->toThrow(UniqueConstraintViolationException::class)
            ->and(ItineraryStop::nextSequenceFor($item))->toBe(3);

        ItineraryStop::factory()->for($item)->create(['sequence' => ItineraryStop::nextSequenceFor($item)]);

        expect($item->itineraryStops()->pluck('sequence')->all())->toBe([1, 3]);
    });
});

test('a tour\'s first stop takes sequence 1', function () {
    $tenant = tenantOnDomain();

    expect(withinTenant($tenant, fn () => ItineraryStop::nextSequenceFor(Item::factory()->create())))->toBe(1);
});
