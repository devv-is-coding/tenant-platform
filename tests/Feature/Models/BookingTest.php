<?php

use App\Models\Booking;
use App\Models\BookingLine;
use App\ValueObjects\Money;

test('a booking\'s amounts stay in the currency it was booked in after the agency switches currency', function () {
    $tenant = tenantOnDomain();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('599.00', 'PHP')]));

    $tenant->update(['currency' => 'JPY']);
    $total = withinTenant($tenant, fn (): ?Money => $booking->fresh()?->total);

    expect($total?->currency)->toBe('PHP')
        ->and($total?->toDecimal())->toBe('599.00');
});

test('a booking\'s lines are its own, in the order they were booked', function () {
    $tenant = tenantOnDomain();

    $lines = withinTenant($tenant, function (): array {
        $booking = Booking::factory()->create();
        $first = BookingLine::factory()->for($booking)->create();
        $second = BookingLine::factory()->for($booking)->create(['option_name' => 'Couple']);
        BookingLine::factory()->create();

        return [$booking->lines()->pluck('id')->all(), [$first->id, $second->id]];
    });

    expect($lines[0])->toBe($lines[1]);
});
