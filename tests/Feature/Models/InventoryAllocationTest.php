<?php

use App\Models\BookingLine;
use App\Models\InventoryAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
 * The concurrency guarantee lives in the schema, not in a lock: SQLite, where
 * the suite runs, compiles lockForUpdate() to nothing (F1), so this test with
 * no parallelism at all is the one that proves overselling cannot happen.
 */
test('the schema refuses a second holder of the same unit on the same date', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        $line = BookingLine::factory()->create();
        $unit = ['option_id' => $line->option_id, 'booking_id' => $line->booking_id, 'booking_line_id' => $line->id, 'occupied_on' => '2026-12-01', 'unit_index' => 0];
        DB::table('inventory_allocations')->insert($unit);

        expect(fn () => DB::table('inventory_allocations')->insert($unit))->toThrow(UniqueConstraintViolationException::class);

        DB::table('inventory_allocations')->insert([...$unit, 'unit_index' => 1]);
        DB::table('inventory_allocations')->insert([...$unit, 'occupied_on' => '2026-12-02']);

        expect(InventoryAllocation::count())->toBe(3);
    });
});

test('a held date is stored as a plain date, so the same date written as a date always collides', function () {
    $tenant = tenantOnDomain();

    $stored = withinTenant($tenant, function (): string {
        $line = BookingLine::factory()->create();
        $held = InventoryAllocation::query()->forceCreate([
            'option_id' => $line->option_id,
            'booking_id' => $line->booking_id,
            'booking_line_id' => $line->id,
            'occupied_on' => CarbonImmutable::parse('2026-12-01 18:30:00')->startOfDay(),
            'unit_index' => 0,
        ]);

        expect(fn () => DB::table('inventory_allocations')->insert(['option_id' => $line->option_id, 'booking_id' => $line->booking_id, 'booking_line_id' => $line->id, 'occupied_on' => '2026-12-01', 'unit_index' => 0]))
            ->toThrow(UniqueConstraintViolationException::class);

        return (string) DB::table('inventory_allocations')->where('id', $held->id)->value('occupied_on');
    });

    expect($stored)->toBe('2026-12-01');
});
