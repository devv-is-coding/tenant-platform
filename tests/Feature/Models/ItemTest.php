<?php

use App\Enums\ItemStatus;
use App\Enums\ItemType;
use App\Models\HotelProfile;
use App\Models\Item;
use App\Models\ItineraryStop;
use App\Models\Media;
use App\Models\TourProfile;
use App\Models\VisaProfile;

test('an item reads its type and status as enums, and profile() gives its own type\'s profile', function (string $profileModel) {
    $tenant = tenantOnDomain();

    [$item, $profile] = withinTenant($tenant, function () use ($profileModel): array {
        $profile = $profileModel::factory()->create();

        return [$profile->item->fresh(), $profile];
    });

    expect($item->item_type)->toBe($profileModel::itemType())
        ->and($item->item_status)->toBe(ItemStatus::Draft)
        ->and(withinTenant($tenant, fn () => $item->profile()?->id))->toBe($profile->id);
})->with([
    'a tour' => TourProfile::class,
    'a visa' => VisaProfile::class,
    'a hotel' => HotelProfile::class,
]);

test('an agency\'s items are in its own database: another agency never sees them, and may reuse the slug', function () {
    $dumaguete = tenantOnDomain();
    $tanjay = tenantOnDomain();

    withinTenant($dumaguete, fn () => Item::factory()->create(['slug' => 'japan-discovery', 'name' => 'Dumaguete\'s Japan']));
    withinTenant($tanjay, fn () => Item::factory()->create(['slug' => 'japan-discovery', 'name' => 'Tanjay\'s Japan']));

    expect(withinTenant($dumaguete, fn () => Item::query()->pluck('name')->all()))->toBe(['Dumaguete\'s Japan'])
        ->and(withinTenant($tanjay, fn () => Item::query()->pluck('name')->all()))->toBe(['Tanjay\'s Japan']);
});

test('a tour\'s stops come in route order and its gallery in display order', function () {
    $tenant = tenantOnDomain();

    [$stops, $gallery] = withinTenant($tenant, function (): array {
        $item = Item::factory()->create();
        ItineraryStop::factory()->for($item)->create(['sequence' => 2, 'location' => 'Hokkaido']);
        ItineraryStop::factory()->for($item)->create(['sequence' => 1, 'location' => 'Tokyo']);
        Media::factory()->for($item)->create(['sort_order' => 2, 'file_name' => 'second.jpg']);
        Media::factory()->for($item)->create(['sort_order' => 1, 'file_name' => 'first.jpg']);

        return [$item->itineraryStops->pluck('location')->all(), $item->media->pluck('file_name')->all()];
    });

    expect($stops)->toBe(['Tokyo', 'Hokkaido'])
        ->and($gallery)->toBe(['first.jpg', 'second.jpg']);
});

test('only tours have an itinerary', function () {
    expect(array_map(fn (ItemType $type): bool => $type->allowsItinerary(), ItemType::cases()))->toBe([true, false, false]);
});
