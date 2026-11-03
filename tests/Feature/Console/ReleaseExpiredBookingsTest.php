<?php

use App\Enums\AuditAction;
use App\Enums\BookingApprovalStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Booking;
use App\Models\InventoryAllocation;
use App\Models\Settings;
use App\Models\User;
use App\Services\Booking\BookingDraftLine;
use App\Services\Booking\BookingService;
use App\Services\Inventory\AvailabilityService;

use function Pest\Laravel\artisan;
use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

test('an unconfirmed, unpaid hold past its time expires and frees its units for sale again', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    travelTo('2026-11-03 10:00:00');

    $booking = withinTenant($tenant, function () use ($customer): Booking {
        Settings::current()->update(['booking_hold_minutes' => 30]);

        return bookFor($customer, [new BookingDraftLine(seatOnTour(stock: 1)->id)]);
    });
    travel(31)->minutes();

    artisan('bookings:release-expired')->expectsOutputToContain('Expired 1 booking(s).')->assertSuccessful();

    $after = withinTenant($tenant, fn (): array => [
        'status' => $booking->fresh()?->approval_status,
        'payment' => $booking->fresh()?->payment_status,
        'held' => InventoryAllocation::count(),
        'available' => app(AvailabilityService::class)->availableFor($booking->lines()->sole()->option()->firstOrFail()),
        'event' => AuditEvent::query()->where('action', AuditAction::BookingExpired)->sole(),
    ]);

    expect($after['status'])->toBe(BookingApprovalStatus::Expired)
        ->and($after['payment'])->toBe(BookingPaymentStatus::Unpaid)
        ->and($after['held'])->toBe(0)
        ->and($after['available'])->toBe(1)
        ->and($after['event']->actor_id)->toBeNull()
        ->and($after['event']->subject_id)->toBe($booking->id);
});

test('a hold still within its time, approved, or with money against it is left alone', function (int $minutesLater, array $state) {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    travelTo('2026-11-03 10:00:00');

    $booking = withinTenant($tenant, function () use ($customer, $state): Booking {
        Settings::current()->update(['booking_hold_minutes' => 30]);
        $booking = bookFor($customer, [new BookingDraftLine(seatOnTour()->id)]);
        $booking->forceFill($state)->save();

        return $booking;
    });
    travel($minutesLater)->minutes();

    artisan('bookings:release-expired')->expectsOutputToContain('Expired 0 booking(s).')->assertSuccessful();

    expect(withinTenant($tenant, fn (): array => [$booking->fresh()?->approval_status, InventoryAllocation::count()]))
        ->toBe([$state['approval_status'] ?? BookingApprovalStatus::Pending, 1]);
})->with([
    'not yet run out' => [29, []],
    'approved' => [31, ['approval_status' => BookingApprovalStatus::Approved]],
    'partly paid' => [31, ['payment_status' => BookingPaymentStatus::PartiallyPaid]],
    'paid' => [31, ['payment_status' => BookingPaymentStatus::Paid]],
]);

test('a booking approved after it was picked up is left alone when its turn comes', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    travelTo('2026-11-03 10:00:00');

    $expired = withinTenant($tenant, function () use ($customer): bool {
        Settings::current()->update(['booking_hold_minutes' => 30]);
        $picked = bookFor($customer, [new BookingDraftLine(seatOnTour()->id)]);
        travel(31)->minutes();
        Booking::query()->whereKey($picked->id)->update(['approval_status' => BookingApprovalStatus::Approved]);

        return app(BookingService::class)->expire($picked);
    });

    expect($expired)->toBeFalse();
});

test('the sweep runs in every agency', function () {
    $dumaguete = tenantOnDomain();
    $tanjay = tenantOnDomain();
    travelTo('2026-11-03 10:00:00');

    foreach ([$dumaguete, $tanjay] as $tenant) {
        $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
        withinTenant($tenant, function () use ($customer): void {
            Settings::current()->update(['booking_hold_minutes' => 30]);
            bookFor($customer, [new BookingDraftLine(seatOnTour()->id)]);
        });
    }
    travel(31)->minutes();

    artisan('bookings:release-expired')->expectsOutputToContain('Expired 2 booking(s).')->assertSuccessful();
});
