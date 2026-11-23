<?php

use App\Enums\AuditAction;
use App\Enums\BookingApprovalStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Booking;
use App\Models\User;

use function Pest\Laravel\artisan;
use function Pest\Laravel\travelTo;

test('a checked-in booking completes the day after its last day', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    $booking = withinTenant($tenant, function () use ($customer): Booking {
        $booking = approvedBookingOn(tourStop(), $customer);
        $booking->forceFill(['fulfilment_status' => FulfilmentStatus::CheckedIn])->save();

        return $booking;
    });
    travelTo('2026-12-13 00:05:00');

    artisan('bookings:complete-finished')->expectsOutputToContain('Completed 1 booking(s).')->assertSuccessful();

    $after = withinTenant($tenant, fn (): array => [
        'status' => $booking->fresh()?->fulfilment_status,
        'event' => AuditEvent::query()->where('action', AuditAction::BookingCompleted)->sole(),
    ]);
    expect($after['status'])->toBe(FulfilmentStatus::Completed)
        ->and($after['event']->actor_id)->toBeNull()
        ->and($after['event']->subject_id)->toBe($booking->id)
        ->and($after['event']->description)->toBe('DGTE-2026-0002 completed: its last day was Dec 12, 2026.');
});

test('a booking still on its last day, never checked in, not approved, or with no end date is left alone', function (string $now, array $state, bool $dated, FulfilmentStatus $expected) {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    $booking = withinTenant($tenant, function () use ($customer, $state, $dated): Booking {
        $booking = $dated ? approvedBookingOn(tourStop(), $customer) : Booking::factory()->approved()->create();
        $booking->forceFill($state)->save();

        return $booking;
    });
    travelTo($now);

    artisan('bookings:complete-finished')->expectsOutputToContain('Completed 0 booking(s).')->assertSuccessful();

    expect(withinTenant($tenant, fn (): ?FulfilmentStatus => $booking->fresh()?->fulfilment_status))->toBe($expected);
})->with([
    'on its last day' => ['2026-12-12 23:00:00', ['fulfilment_status' => FulfilmentStatus::CheckedIn], true, FulfilmentStatus::CheckedIn],
    'never checked in' => ['2026-12-13 00:05:00', [], true, FulfilmentStatus::NotStarted],
    'cancelled after checking in' => ['2026-12-13 00:05:00', ['fulfilment_status' => FulfilmentStatus::CheckedIn, 'approval_status' => BookingApprovalStatus::Cancelled], true, FulfilmentStatus::CheckedIn],
    'no end date' => ['2026-12-13 00:05:00', ['fulfilment_status' => FulfilmentStatus::CheckedIn], false, FulfilmentStatus::CheckedIn],
]);
