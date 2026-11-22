<?php

use App\Enums\AuditAction;
use App\Enums\BookingApprovalStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Booking;
use App\Models\CheckIn;
use App\Models\ItineraryStop;
use App\Models\User;
use App\Services\Fulfilment\FulfilmentService;
use App\Services\Fulfilment\QrTokenSigner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\travelTo;

test('staff scanning the guest\'s booking code check the guest in', function () {
    travelTo('2026-12-01 14:12:00');
    $tenant = tenantOnDomain();
    $staff = actingAsTenantUser($tenant);
    [$booking, $token] = withinTenant($tenant, function (): array {
        $booking = Booking::factory()->approved()->create(['reference' => 'DGTE-2026-0001']);

        return [$booking, app(QrTokenSigner::class)->forBooking($booking)];
    });

    withinTenant($tenant, fn (): CheckIn => app(FulfilmentService::class)->checkInWithBookingQr($token, $staff));

    $after = withinTenant($tenant, fn (): array => [
        'checkIns' => CheckIn::query()->get()->map(fn (CheckIn $checkIn): array => [
            $checkIn->booking_id, $checkIn->itinerary_stop_id, $checkIn->customer_id, $checkIn->recorded_by, $checkIn->checked_in_at->toDateTimeString(),
        ])->all(),
        'status' => $booking->fresh()?->fulfilment_status,
        'logged' => AuditEvent::query()->where('action', AuditAction::CheckInRecorded)->get()->map(fn (AuditEvent $event): array => [
            $event->actor_id, $event->subject_id, $event->description, $event->changes,
        ])->all(),
    ]);
    expect($after['checkIns'])->toBe([[$booking->id, null, $booking->customer_id, $staff->id, '2026-12-01 14:12:00']])
        ->and($after['status'])->toBe(FulfilmentStatus::CheckedIn)
        ->and($after['logged'])->toBe([[
            $staff->id, $booking->id, 'DGTE-2026-0001 checked in.', ['fulfilment_status' => ['old' => 1, 'new' => 2]],
        ]]);
});

test('a booking\'s code is refused once the guest has checked in with it', function () {
    $tenant = tenantOnDomain();
    $staff = actingAsTenantUser($tenant);
    $token = withinTenant($tenant, fn (): string => app(QrTokenSigner::class)->forBooking(
        Booking::factory()->approved()->create(['reference' => 'DGTE-2026-0001']),
    ));
    withinTenant($tenant, fn (): CheckIn => app(FulfilmentService::class)->checkInWithBookingQr($token, $staff));

    expect(fn (): CheckIn => withinTenant($tenant, fn (): CheckIn => app(FulfilmentService::class)->checkInWithBookingQr($token, $staff)))
        ->toThrow(ValidationException::class, 'DGTE-2026-0001 is already checked in.');

    expect(withinTenant($tenant, fn (): int => CheckIn::query()->count()))->toBe(1);
});

test('a booking that is not approved cannot be checked in, so a cancelled booking\'s code is refused', function (BookingApprovalStatus $status, string $message) {
    $tenant = tenantOnDomain();
    $staff = actingAsTenantUser($tenant);
    [$booking, $token] = withinTenant($tenant, function () use ($status): array {
        $booking = Booking::factory()->create(['approval_status' => $status, 'reference' => 'DGTE-2026-0001']);

        return [$booking, app(QrTokenSigner::class)->forBooking($booking)];
    });

    expect(fn (): CheckIn => withinTenant($tenant, fn (): CheckIn => app(FulfilmentService::class)->checkInWithBookingQr($token, $staff)))
        ->toThrow(ValidationException::class, $message);

    expect(withinTenant($tenant, fn (): array => [CheckIn::query()->count(), $booking->fresh()?->fulfilment_status]))
        ->toBe([0, FulfilmentStatus::NotStarted]);
})->with([
    'cancelled' => [BookingApprovalStatus::Cancelled, 'DGTE-2026-0001 is cancelled, so it cannot be checked in.'],
    'pending' => [BookingApprovalStatus::Pending, 'DGTE-2026-0001 is pending approval, so it cannot be checked in.'],
    'rejected' => [BookingApprovalStatus::Rejected, 'DGTE-2026-0001 is rejected, so it cannot be checked in.'],
    'expired' => [BookingApprovalStatus::Expired, 'DGTE-2026-0001 is expired, so it cannot be checked in.'],
]);

test('another agency\'s booking code is refused, even where a booking has the same id', function () {
    $dumaguete = tenantOnDomain();
    $tanjay = tenantOnDomain();
    $token = withinTenant($dumaguete, fn (): string => app(QrTokenSigner::class)->forBooking(Booking::factory()->approved()->create()));
    $tanjayBooking = withinTenant($tanjay, fn (): Booking => Booking::factory()->approved()->create());
    $tanjayStaff = actingAsTenantUser($tanjay);

    expect(fn (): CheckIn => withinTenant($tanjay, fn (): CheckIn => app(FulfilmentService::class)->checkInWithBookingQr($token, $tanjayStaff)))
        ->toThrow(ValidationException::class, 'This QR code is not valid here.');

    expect(withinTenant($tanjay, fn (): array => [CheckIn::query()->count(), $tanjayBooking->id]))->toBe([0, 1]);
});

test('a deleted booking\'s code is refused', function () {
    $tenant = tenantOnDomain();
    $staff = actingAsTenantUser($tenant);
    $token = withinTenant($tenant, function (): string {
        $booking = Booking::factory()->approved()->create();
        $token = app(QrTokenSigner::class)->forBooking($booking);
        $booking->delete();

        return $token;
    });

    expect(fn (): CheckIn => withinTenant($tenant, fn (): CheckIn => app(FulfilmentService::class)->checkInWithBookingQr($token, $staff)))
        ->toThrow(ValidationException::class, 'This QR code is not valid here.');
});

test('a guest who finished their booking cannot be checked in again', function (FulfilmentStatus $status, string $message) {
    $tenant = tenantOnDomain();
    $staff = actingAsTenantUser($tenant);
    $token = withinTenant($tenant, fn (): string => app(QrTokenSigner::class)->forBooking(
        Booking::factory()->approved()->create(['fulfilment_status' => $status, 'reference' => 'DGTE-2026-0001']),
    ));

    expect(fn (): CheckIn => withinTenant($tenant, fn (): CheckIn => app(FulfilmentService::class)->checkInWithBookingQr($token, $staff)))
        ->toThrow(ValidationException::class, $message);
})->with([
    'completed' => [FulfilmentStatus::Completed, 'DGTE-2026-0001 is completed, so it cannot be checked in.'],
    'a no-show' => [FulfilmentStatus::NoShow, 'DGTE-2026-0001 is no show, so it cannot be checked in.'],
]);

test('the guest checks in at a tour stop by scanning the code on the guide\'s device', function () {
    travelTo('2026-12-02 09:40:00');
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    [$stop, $booking, $token] = withinTenant($tenant, function () use ($customer): array {
        $stop = tourStop();

        return [$stop, approvedBookingOn($stop, $customer), app(QrTokenSigner::class)->forStop($stop)];
    });

    withinTenant($tenant, fn (): CheckIn => app(FulfilmentService::class)->checkInAtStop($booking, $token, $customer));

    $after = withinTenant($tenant, fn (): array => [
        'checkIns' => CheckIn::query()->get()->map(fn (CheckIn $checkIn): array => [
            $checkIn->booking_id, $checkIn->itinerary_stop_id, $checkIn->stop_label, $checkIn->customer_id, $checkIn->recorded_by, $checkIn->checked_in_at->toDateTimeString(),
        ])->all(),
        'status' => $booking->fresh()?->fulfilment_status,
        'logged' => AuditEvent::query()->where('action', AuditAction::CheckInRecorded)->pluck('description')->all(),
    ]);
    expect($after['checkIns'])->toBe([[$booking->id, $stop->id, 'Tokyo', $customer->id, null, '2026-12-02 09:40:00']])
        ->and($after['status'])->toBe(FulfilmentStatus::CheckedIn)
        ->and($after['logged'])->toBe(['DGTE-2026-0002 checked in at Tokyo.']);
});

test('a guest checks in at each stop only once per booking', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    [$booking, $token] = withinTenant($tenant, function () use ($customer): array {
        $stop = tourStop();

        return [approvedBookingOn($stop, $customer), app(QrTokenSigner::class)->forStop($stop)];
    });
    withinTenant($tenant, fn (): CheckIn => app(FulfilmentService::class)->checkInAtStop($booking, $token, $customer));

    expect(fn (): CheckIn => withinTenant($tenant, fn (): CheckIn => app(FulfilmentService::class)->checkInAtStop($booking, $token, $customer)))
        ->toThrow(ValidationException::class, 'You already checked in at Tokyo.');

    expect(withinTenant($tenant, fn (): int => CheckIn::query()->count()))->toBe(1);
});

test('a repeat customer checks in at a stop on each of their bookings on the tour', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    [$first, $second, $token] = withinTenant($tenant, function () use ($customer): array {
        $stop = tourStop();

        return [
            approvedBookingOn($stop, $customer, 'DGTE-2026-0002'),
            approvedBookingOn($stop, $customer, 'DGTE-2026-0003'),
            app(QrTokenSigner::class)->forStop($stop),
        ];
    });
    withinTenant($tenant, fn (): CheckIn => app(FulfilmentService::class)->checkInAtStop($first, $token, $customer));

    withinTenant($tenant, fn (): CheckIn => app(FulfilmentService::class)->checkInAtStop($second, $token, $customer));

    expect(withinTenant($tenant, fn (): array => CheckIn::query()->orderBy('id')->pluck('booking_id')->all()))
        ->toBe([$first->id, $second->id]);
});

test('the database refuses a second check-in at one stop on one booking', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    [$stop, $booking] = withinTenant($tenant, function () use ($customer): array {
        $stop = tourStop();

        return [$stop, approvedBookingOn($stop, $customer)];
    });
    withinTenant($tenant, fn (): CheckIn => CheckIn::factory()->for($booking)->create(['itinerary_stop_id' => $stop->id]));

    expect(fn (): CheckIn => withinTenant($tenant, fn (): CheckIn => CheckIn::factory()->for($booking)->create(['itinerary_stop_id' => $stop->id])))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a guest cannot check in on someone else\'s booking', function () {
    $tenant = tenantOnDomain();
    [$customer, $other] = User::factory()->count(2)->role(Role::Customer)->for($tenant)->create()->all();
    [$booking, $token] = withinTenant($tenant, function () use ($other): array {
        $stop = tourStop();

        return [approvedBookingOn($stop, $other), app(QrTokenSigner::class)->forStop($stop)];
    });

    expect(fn (): CheckIn => withinTenant($tenant, fn (): CheckIn => app(FulfilmentService::class)->checkInAtStop($booking, $token, $customer)))
        ->toThrow(ValidationException::class, 'That booking is not yours.');

    expect(withinTenant($tenant, fn (): int => CheckIn::query()->count()))->toBe(0);
});

test('a stop\'s code is refused on a booking for another tour', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    [$booking, $token] = withinTenant($tenant, function () use ($customer): array {
        $booked = tourStop();
        $elsewhere = ItineraryStop::factory()->create(['item_id' => seatOnTour()->item_id, 'location' => 'Seoul']);

        return [approvedBookingOn($booked, $customer), app(QrTokenSigner::class)->forStop($elsewhere)];
    });

    expect(fn (): CheckIn => withinTenant($tenant, fn (): CheckIn => app(FulfilmentService::class)->checkInAtStop($booking, $token, $customer)))
        ->toThrow(ValidationException::class, 'Seoul is not on DGTE-2026-0002.');

    expect(withinTenant($tenant, fn (): int => CheckIn::query()->count()))->toBe(0);
});

test('staff mark a booking completed by hand, whether or not the guest checked in by QR', function (FulfilmentStatus $from) {
    $tenant = tenantOnDomain();
    actingAsTenantUser($tenant);
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->approved()->create(['fulfilment_status' => $from, 'reference' => 'DGTE-2026-0001']));

    withinTenant($tenant, fn (): Booking => app(FulfilmentService::class)->markCompleted($booking));

    expect(withinTenant($tenant, fn (): array => [
        $booking->fresh()?->fulfilment_status,
        AuditEvent::query()->where('action', AuditAction::BookingCompleted)->get()->map(fn (AuditEvent $event): array => [$event->description, $event->changes])->all(),
    ]))->toBe([
        FulfilmentStatus::Completed,
        [['DGTE-2026-0001 marked completed.', ['fulfilment_status' => ['old' => $from->value, 'new' => 3]]]],
    ]);
})->with([
    'never checked in' => [FulfilmentStatus::NotStarted],
    'checked in by QR' => [FulfilmentStatus::CheckedIn],
]);

test('staff mark a guest who never came a no-show', function () {
    $tenant = tenantOnDomain();
    actingAsTenantUser($tenant);
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->approved()->create(['reference' => 'DGTE-2026-0001']));

    withinTenant($tenant, fn (): Booking => app(FulfilmentService::class)->markNoShow($booking));

    expect(withinTenant($tenant, fn (): array => [
        $booking->fresh()?->fulfilment_status,
        AuditEvent::query()->where('action', AuditAction::BookingMarkedNoShow)->pluck('description')->all(),
    ]))->toBe([FulfilmentStatus::NoShow, ['DGTE-2026-0001 marked a no-show.']]);
});

test('a guest who checked in, or a finished booking, cannot be marked a no-show', function (FulfilmentStatus $from, string $message) {
    $tenant = tenantOnDomain();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->approved()->create(['fulfilment_status' => $from, 'reference' => 'DGTE-2026-0001']));

    expect(fn (): Booking => withinTenant($tenant, fn (): Booking => app(FulfilmentService::class)->markNoShow($booking)))
        ->toThrow(ValidationException::class, $message);

    expect(withinTenant($tenant, fn (): ?FulfilmentStatus => $booking->fresh()?->fulfilment_status))->toBe($from);
})->with([
    'checked in' => [FulfilmentStatus::CheckedIn, 'DGTE-2026-0001 is checked in, so the guest was not a no-show.'],
    'completed' => [FulfilmentStatus::Completed, 'DGTE-2026-0001 is completed, so the guest was not a no-show.'],
]);

test('a finished booking cannot be marked completed again', function (FulfilmentStatus $from, string $message) {
    $tenant = tenantOnDomain();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->approved()->create(['fulfilment_status' => $from, 'reference' => 'DGTE-2026-0001']));

    expect(fn (): Booking => withinTenant($tenant, fn (): Booking => app(FulfilmentService::class)->markCompleted($booking)))
        ->toThrow(ValidationException::class, $message);

    expect(withinTenant($tenant, fn (): ?FulfilmentStatus => $booking->fresh()?->fulfilment_status))->toBe($from);
})->with([
    'completed' => [FulfilmentStatus::Completed, 'DGTE-2026-0001 is already completed.'],
    'a no-show' => [FulfilmentStatus::NoShow, 'DGTE-2026-0001 is already no show.'],
]);

test('a booking that is not approved cannot be marked completed or a no-show', function (Closure $mark, string $message) {
    $tenant = tenantOnDomain();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->cancelled()->create(['reference' => 'DGTE-2026-0001']));

    expect(fn (): Booking => withinTenant($tenant, fn (): Booking => $mark(app(FulfilmentService::class), $booking)))
        ->toThrow(ValidationException::class, $message);

    expect(withinTenant($tenant, fn (): ?FulfilmentStatus => $booking->fresh()?->fulfilment_status))->toBe(FulfilmentStatus::NotStarted);
})->with([
    'completed' => [fn (FulfilmentService $service, Booking $booking): Booking => $service->markCompleted($booking), 'DGTE-2026-0001 is cancelled, so it cannot be completed.'],
    'a no-show' => [fn (FulfilmentService $service, Booking $booking): Booking => $service->markNoShow($booking), 'DGTE-2026-0001 is cancelled, so it cannot be marked a no-show.'],
]);

test('a booking cannot be marked completed or a no-show before its first day', function (Closure $mark) {
    travelTo('2026-11-30 18:00:00');
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => approvedBookingOn(tourStop(), $customer));

    expect(fn (): Booking => withinTenant($tenant, fn (): Booking => $mark(app(FulfilmentService::class), $booking)))
        ->toThrow(ValidationException::class, 'DGTE-2026-0002 does not start until Dec 1, 2026.');

    expect(withinTenant($tenant, fn (): ?FulfilmentStatus => $booking->fresh()?->fulfilment_status))->toBe(FulfilmentStatus::NotStarted);
})->with([
    'completed' => [fn (FulfilmentService $service, Booking $booking): Booking => $service->markCompleted($booking)],
    'a no-show' => [fn (FulfilmentService $service, Booking $booking): Booking => $service->markNoShow($booking)],
]);

test('a booking can be marked a no-show from its first day', function () {
    travelTo('2026-12-01 00:00:00');
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => approvedBookingOn(tourStop(), $customer));

    withinTenant($tenant, fn (): Booking => app(FulfilmentService::class)->markNoShow($booking));

    expect(withinTenant($tenant, fn (): ?FulfilmentStatus => $booking->fresh()?->fulfilment_status))->toBe(FulfilmentStatus::NoShow);
});

test('a booking staff completed by hand after the daily run picked it up is left as it is', function () {
    travelTo('2026-12-13 00:05:00');
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    $pickedUp = withinTenant($tenant, function () use ($customer): Booking {
        $booking = approvedBookingOn(tourStop(), $customer);
        $booking->forceFill(['fulfilment_status' => FulfilmentStatus::CheckedIn])->save();

        return $booking;
    });
    withinTenant($tenant, fn (): Booking => app(FulfilmentService::class)->markCompleted($pickedUp));

    $completed = withinTenant($tenant, fn (): bool => app(FulfilmentService::class)->completeIfOver($pickedUp));

    expect($completed)->toBeFalse()
        ->and(withinTenant($tenant, fn (): int => AuditEvent::query()->where('action', AuditAction::BookingCompleted)->count()))->toBe(1);
});
