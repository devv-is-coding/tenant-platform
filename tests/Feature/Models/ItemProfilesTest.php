<?php

use App\Models\HotelProfile;
use App\Models\Item;
use App\Models\ItineraryStop;
use App\Models\TourProfile;
use App\Models\VisaProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The typed profiles that replace the legacy EAV (defect 6): what the schema
 * holds, and what the models refuse because the schema cannot.
 */

test('an item cannot be given another type\'s profile or, unless it is a tour, an itinerary', function (Closure $makeItem, string $model, array $attributes) {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function () use ($makeItem, $model, $attributes): void {
        $item = $makeItem();

        expect(fn () => $model::factory()->for($item)->create($attributes))
            ->toThrow(LogicException::class, "only a {$model::itemType()->label()} item can");

        expect($model::count())->toBe(0);
    });
})->with([
    'a tour profile on a hotel' => [fn () => Item::factory()->hotel()->create(), TourProfile::class, []],
    'a tour profile on a visa' => [fn () => Item::factory()->visa()->create(), TourProfile::class, []],
    'a visa profile on a tour' => [fn () => Item::factory()->create(), VisaProfile::class, []],
    'a hotel profile on a tour' => [fn () => Item::factory()->create(), HotelProfile::class, []],
    'an itinerary stop on a hotel' => [fn () => Item::factory()->hotel()->create(), ItineraryStop::class, []],
]);

test('defect 3: a departure window ending before it starts, like the legacy tour ending in the year 0026, is refused', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        expect(fn () => TourProfile::factory()->create(['departs_on' => '2026-03-10', 'returns_on' => '0026-03-17']))
            ->toThrow(InvalidArgumentException::class, 'A range cannot end (0026-03-17) before it starts (2026-03-10).');

        expect(TourProfile::count())->toBe(0);
    });
});

test('a one-day tour, departing and returning the same day, is allowed', function () {
    $tenant = tenantOnDomain();

    $nights = withinTenant($tenant, fn () => TourProfile::factory()->create(['departs_on' => '2026-12-01', 'returns_on' => '2026-12-01'])->departureWindow()->nights());

    expect($nights)->toBe(0);
});

test('a visa cannot take at least longer to process than it takes at most', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        expect(fn () => VisaProfile::factory()->create(['processing_days_min' => 10, 'processing_days_max' => 5]))
            ->toThrow(InvalidArgumentException::class, 'Processing cannot take at least 10 days but at most 5.');
    });
});

test('inclusions, requirements and amenities round-trip as lists of strings', function (string $model, string $attribute) {
    $tenant = tenantOnDomain();
    $list = ['Return airfare', 'Ñandú tours', 'Café breakfast'];

    $stored = withinTenant($tenant, fn () => $model::query()->findOrFail($model::factory()->create([$attribute => $list])->id)->{$attribute});

    expect($stored)->toBe($list);
})->with([
    'a tour\'s inclusions' => [TourProfile::class, 'inclusions'],
    'a visa\'s requirements' => [VisaProfile::class, 'requirements'],
    'a hotel\'s amenities' => [HotelProfile::class, 'amenities'],
]);

test('a list holding anything but strings is refused before it is stored', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        expect(fn () => HotelProfile::factory()->create(['amenities' => ['Wi-Fi', 2]]))
            ->toThrow(InvalidArgumentException::class, '[amenities] must be a list of strings; found a int.');
    });
});

test('a hotel\'s check-in and check-out are times, not text', function () {
    $tenant = tenantOnDomain();

    $stored = withinTenant($tenant, function (): array {
        HotelProfile::factory()->create(['check_in_time' => '14:00:00', 'check_out_time' => '12:00:00']);

        return (array) DB::table('hotel_profiles')->first(['check_in_time', 'check_out_time']);
    });

    expect($stored)->toBe(['check_in_time' => '14:00:00', 'check_out_time' => '12:00:00']);
});

test('each type\'s required attributes are NOT NULL in the schema itself', function (string $table, Closure $row, string $missing) {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function () use ($table, $row, $missing): void {
        $values = $row(Item::factory()->create()->id);
        unset($values[$missing]);

        expect(fn () => DB::table($table)->insert($values))->toThrow(QueryException::class);
    });
})->with([
    'a tour without a meeting point' => ['tour_profiles', fn (int $itemId) => ['item_id' => $itemId, 'max_adults' => 2, 'meeting_point' => 'NAIA', 'inclusions' => '[]', 'departs_on' => '2026-12-01', 'returns_on' => '2026-12-05'], 'meeting_point'],
    'a tour without a return date' => ['tour_profiles', fn (int $itemId) => ['item_id' => $itemId, 'max_adults' => 2, 'meeting_point' => 'NAIA', 'inclusions' => '[]', 'departs_on' => '2026-12-01', 'returns_on' => '2026-12-05'], 'returns_on'],
    'a visa without a processing time' => ['visa_profiles', fn (int $itemId) => ['item_id' => $itemId, 'processing_days_min' => 5, 'processing_days_max' => 10, 'validity_days' => 90, 'requirements' => '[]'], 'processing_days_min'],
    'a hotel without a check-in time' => ['hotel_profiles', fn (int $itemId) => ['item_id' => $itemId, 'max_adults' => 2, 'amenities' => '[]', 'check_in_time' => '14:00:00', 'check_out_time' => '12:00:00'], 'check_in_time'],
    'an item without a type' => ['items', fn (int $itemId) => ['item_type' => 1, 'name' => 'Untyped', 'slug' => 'untyped'], 'item_type'],
]);

test('an item has at most one profile of its type', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        $item = Item::factory()->create();
        TourProfile::factory()->for($item)->create();

        expect(fn () => TourProfile::factory()->for($item)->create())->toThrow(QueryException::class);
    });
});
