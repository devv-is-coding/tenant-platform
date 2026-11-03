<?php

use App\Enums\InventoryBatchReason;
use App\Models\InventoryBatch;
use App\Models\Item;
use App\Models\Option;
use App\Services\Inventory\AvailabilityService;
use Illuminate\Support\Facades\DB;

/**
 * Hold units of the option the way a booking will: one allocation row per
 * unit, under a booking and line written as bare rows until those models exist.
 */
function holdUnitsOf(Option $option, int $units): void
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

    DB::table('inventory_allocations')->insert(array_map(fn (int $unit): array => [
        'option_id' => $option->id,
        'booking_id' => $bookingId,
        'booking_line_id' => $lineId,
        'occupied_on' => '2026-12-01',
        'unit_index' => $unit,
    ], range(0, $units - 1)));
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
