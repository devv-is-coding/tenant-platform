<?php

use App\Enums\InventoryBatchReason;
use App\Exceptions\OptionUnavailableException;
use App\Models\InventoryBatch;
use App\Models\Item;
use App\Models\Option;
use App\Models\TourProfile;
use App\Services\Inventory\AvailabilityService;
use App\ValueObjects\DateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Hold units of the option the way a booking will: one allocation row per
 * unit per night, under a booking and line written as bare rows until those
 * models exist.
 *
 * @param  list<string>  $nights
 */
function holdUnitsOf(Option $option, int $units, array $nights = ['2026-12-01']): void
{
    $bookingId = DB::table('bookings')->insertGetId([
        'reference' => 'BK-'.fake()->unique()->numerify('######'),
        'customer_id' => 1,
        'customer_name' => 'Maria Santos',
        'customer_email' => 'maria@example.com',
        'currency' => 'PHP',
        'subtotal_minor' => 0,
        'total_minor' => 0,
    ]);

    $lineId = DB::table('booking_lines')->insertGetId([
        'booking_id' => $bookingId,
        'option_id' => $option->id,
        'item_id' => $option->item_id,
        'item_name' => 'Tour',
        'option_name' => $option->name,
        'item_type' => 1,
        'availability_mode' => $option->availability_mode->value,
        'unit_price_minor' => 0,
        'quantity' => $units,
        'line_total_minor' => 0,
    ]);

    $rows = [];

    foreach ($nights as $night) {
        foreach (range(0, $units - 1) as $unit) {
            $rows[] = ['option_id' => $option->id, 'booking_id' => $bookingId, 'booking_line_id' => $lineId, 'occupied_on' => $night, 'unit_index' => $unit];
        }
    }

    DB::table('inventory_allocations')->insert($rows);
}

test('capacity is the sum of the live batches: additions, negative corrections, and no deleted batch', function () {
    $tenant = tenantOnDomain();

    $capacity = withinTenant($tenant, function (): int {
        $option = Option::factory()->create();
        InventoryBatch::factory()->for($option)->create(['quantity' => 10]);
        InventoryBatch::factory()->for($option)->create(['quantity' => 5, 'reason' => InventoryBatchReason::SeasonalAddition]);
        InventoryBatch::factory()->for($option)->correction(-3)->create();
        InventoryBatch::factory()->for($option)->create(['quantity' => 4, 'reason' => InventoryBatchReason::Returned])->delete();

        return app(AvailabilityService::class)->capacityOf($option);
    });

    expect($capacity)->toBe(12);
});

test('every unit a booking holds is one fewer to sell', function () {
    $tenant = tenantOnDomain();

    $available = withinTenant($tenant, function (): int {
        $option = Option::factory()->create();
        InventoryBatch::factory()->for($option)->create(['quantity' => 10]);
        holdUnitsOf($option, 3);

        return (int) app(AvailabilityService::class)->availableFor($option);
    });

    expect($available)->toBe(7);
});

test('a correction below what is already sold leaves nothing to sell, never a negative count', function () {
    $tenant = tenantOnDomain();

    $available = withinTenant($tenant, function (): ?int {
        $option = Option::factory()->create();
        InventoryBatch::factory()->for($option)->create(['quantity' => 4]);
        holdUnitsOf($option, 3);
        InventoryBatch::factory()->for($option)->correction(-2)->create();

        return app(AvailabilityService::class)->availableFor($option);
    });

    expect($available)->toBe(0);
});

test('an option with no batches has nothing to sell', function () {
    $tenant = tenantOnDomain();

    $available = withinTenant($tenant, fn (): ?int => app(AvailabilityService::class)->availableFor(Option::factory()->create()));

    expect($available)->toBe(0);
});

test('one option\'s batches and bookings do not count towards another\'s', function () {
    $tenant = tenantOnDomain();

    $available = withinTenant($tenant, function (): ?int {
        $solo = Option::factory()->create();
        $couple = Option::factory()->for($solo->item)->create(['name' => 'Couple']);
        InventoryBatch::factory()->for($solo)->create(['quantity' => 6]);
        InventoryBatch::factory()->for($couple)->create(['quantity' => 20]);
        holdUnitsOf($couple, 5);

        return app(AvailabilityService::class)->availableFor($solo);
    });

    expect($available)->toBe(6);
});

test('an Unlimited option never runs out', function () {
    $tenant = tenantOnDomain();

    $available = withinTenant($tenant, fn (): ?int => app(AvailabilityService::class)
        ->availableFor(Option::factory()->unlimited()->for(Item::factory()->visa())->create()));

    expect($available)->toBeNull();
});

test('a per-night option\'s availability is not answered without the nights', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        $room = Option::factory()->nightly()->for(Item::factory()->hotel())->create();

        expect(fn () => app(AvailabilityService::class)->availableFor($room))
            ->toThrow(LogicException::class, "Option [{$room->id}] is sold by the night; its availability depends on the nights asked for.");
    });
});

test('a room sells for a stay as many times as its fullest night allows, December-only rooms counting only in December', function () {
    $tenant = tenantOnDomain();

    $available = withinTenant($tenant, function (): array {
        $room = Option::factory()->nightly()->for(Item::factory()->hotel())->create();
        InventoryBatch::factory()->for($room)->create(['quantity' => 2]);
        InventoryBatch::factory()->for($room)->create(['quantity' => 3, 'reason' => InventoryBatchReason::SeasonalAddition, 'effective_from' => '2026-12-01', 'effective_to' => '2026-12-31']);
        holdUnitsOf($room, 4, ['2026-12-30']);

        $service = app(AvailabilityService::class);

        return [
            'in December' => $service->availableFor($room, DateRange::of('2026-12-10', '2026-12-13')),
            'across the busy night' => $service->availableFor($room, DateRange::of('2026-12-29', '2027-01-01')),
            'in January' => $service->availableFor($room, DateRange::of('2027-01-05', '2027-01-07')),
        ];
    });

    expect($available)->toBe(['in December' => 5, 'across the busy night' => 1, 'in January' => 2]);
});

test('a tour seat is counted on the tour\'s departure date: only batches effective then, only seats held then', function () {
    $tenant = tenantOnDomain();

    $available = withinTenant($tenant, function (): ?int {
        $tour = Item::factory()->create();
        TourProfile::factory()->for($tour)->create(['departs_on' => '2026-12-01', 'returns_on' => '2026-12-12']);
        $seat = Option::factory()->for($tour)->create();
        InventoryBatch::factory()->for($seat)->create(['quantity' => 20]);
        InventoryBatch::factory()->for($seat)->create(['quantity' => 5, 'reason' => InventoryBatchReason::SeasonalAddition, 'effective_to' => '2026-11-30']);
        holdUnitsOf($seat, 3, ['2026-12-01']);
        holdUnitsOf($seat, 2, ['2026-11-15']);

        return app(AvailabilityService::class)->availableFor($seat);
    });

    expect($available)->toBe(17);
});

test('a booking the ledger cannot cover is refused with how many are left', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        $room = Option::factory()->nightly()->for(Item::factory()->hotel())->create(['name' => 'Room 101']);
        InventoryBatch::factory()->for($room)->create(['quantity' => 2]);
        holdUnitsOf($room, 1, ['2026-12-06']);

        expect(fn () => app(AvailabilityService::class)->assertCanAllocate($room, 2, DateRange::of('2026-12-05', '2026-12-08')))
            ->toThrow(function (OptionUnavailableException $exception): void {
                expect($exception->requested)->toBe(2)
                    ->and($exception->available)->toBe(1)
                    ->and($exception->getStatusCode())->toBe(409);
            });

        app(AvailabilityService::class)->assertCanAllocate($room, 1, DateRange::of('2026-12-05', '2026-12-08'));
    });
});

test('a booking is refused for a quantity below one, or a stay shorter or longer than the room allows', function (int $quantity, string $endsOn, string $message) {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function () use ($quantity, $endsOn, $message): void {
        $room = Option::factory()->nightly()->for(Item::factory()->hotel())->create(['name' => 'Cabana', 'min_nights' => 2, 'max_nights' => 5]);
        InventoryBatch::factory()->for($room)->create(['quantity' => 4]);

        expect(fn () => app(AvailabilityService::class)->assertCanAllocate($room, $quantity, DateRange::of('2026-12-05', $endsOn)))
            ->toThrow(InvalidArgumentException::class, $message);
    });
})->with([
    'no rooms' => [0, '2026-12-08', 'A booking takes at least one unit, not 0.'],
    'one night' => [1, '2026-12-06', '[Cabana] is booked for 2 to 5 nights, not 1.'],
    'six nights' => [1, '2026-12-11', '[Cabana] is booked for 2 to 5 nights, not 6.'],
]);

test('an Unlimited option can always be booked', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        $visa = Option::factory()->unlimited()->for(Item::factory()->visa())->create();

        app(AvailabilityService::class)->assertCanAllocate($visa, 500);

        expect(fn () => app(AvailabilityService::class)->nightlyBreakdown($visa, DateRange::of('2026-12-05', '2026-12-08')))
            ->toThrow(LogicException::class, "Option [{$visa->id}] is Unlimited; it has no stock to show night by night.");
    });
});

test('the breakdown reads each night\'s capacity and held units from the ledger', function () {
    $tenant = tenantOnDomain();

    $breakdown = withinTenant($tenant, function (): array {
        $room = Option::factory()->nightly()->for(Item::factory()->hotel())->create();
        InventoryBatch::factory()->for($room)->create(['quantity' => 3]);
        holdUnitsOf($room, 2, ['2026-12-06']);

        return app(AvailabilityService::class)->nightlyBreakdown($room, DateRange::of('2026-12-05', '2026-12-07'));
    });

    expect(array_map(fn (array $night): array => [...$night, 'night' => $night['night']->toDateString()], $breakdown))->toBe([
        ['night' => '2026-12-05', 'capacity' => 3, 'held' => 0, 'available' => 3],
        ['night' => '2026-12-06', 'capacity' => 3, 'held' => 2, 'available' => 1],
    ]);
});

test('running out renders as a 409 option_unavailable error on the API', function () {
    $tenant = tenantOnDomain();

    $response = withinTenant($tenant, function (): ?JsonResponse {
        $exception = new OptionUnavailableException(Option::factory()->create(), 2, 1);

        return $exception->render(Request::create('/api/v1/bookings', 'POST'));
    });

    expect($response?->getStatusCode())->toBe(409)
        ->and($response?->getData(true))->toBe(['error' => 'option_unavailable', 'requested' => 2, 'available' => 1]);
});
