<?php

namespace App\Services\Fulfilment;

use App\Concerns\RecordsAuditEvents;
use App\Enums\AuditAction;
use App\Enums\BookingApprovalStatus;
use App\Enums\FulfilmentStatus;
use App\Models\Booking;
use App\Models\CheckIn;
use App\Models\ItineraryStop;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Whether the guest turned up: the third status axis (`fulfilment_status`),
 * independent of the approval and the money. A paid, approved booking can
 * still end as a no-show.
 *
 * Two ways in by QR, split by product (P21): staff scan the guest's booking
 * code at a hotel, and the guest scans a tour stop's rotating code on the
 * guide's device. The first check-in moves the booking from NotStarted to
 * CheckedIn, and once its dates are over it completes on its own (decided
 * 2026-11-22). Nothing becomes a no-show on its own: only staff know whether
 * a guest who never scanned came. So staff can also mark a booking Completed
 * or NoShow by hand (TS-4), because QR check-in is a plan feature and not
 * every guest carries the app: without that, such a booking could never
 * complete, and a review needs a completed booking (TA-21).
 *
 * Only an approved booking can be checked in, so a cancelled booking's code
 * is refused. Every step locks the booking's row first, as payments and
 * refunds do, so two scans at once cannot both check in: that is what keeps
 * one hotel check-in per booking, which the unique key cannot (its stop is
 * NULL, and NULLs never collide).
 */
class FulfilmentService
{
    use RecordsAuditEvents;

    public function __construct(private QrTokenSigner $signer) {}

    /**
     * Check the guest in from the booking code staff scanned (the hotel
     * direction). The code works once: scanned again, it is refused.
     *
     * @throws ValidationException for a bad code, or a booking that cannot be checked in
     */
    public function checkInWithBookingQr(string $token, User $scannedBy): CheckIn
    {
        $bookingId = $this->signer->bookingIdFrom($token);

        return DB::transaction(function () use ($bookingId, $scannedBy): CheckIn {
            $booking = Booking::query()->whereKey($bookingId)->lockForUpdate()->first();

            if ($booking === null) {
                throw ValidationException::withMessages(['qr' => __('This QR code is not valid here.')]);
            }

            $this->ensureCanCheckIn($booking);

            if ($booking->checkIns()->whereNull('itinerary_stop_id')->exists()) {
                throw ValidationException::withMessages(['qr' => __(':reference is already checked in.', ['reference' => $booking->reference])]);
            }

            $checkIn = $booking->checkIns()->create([
                'customer_id' => $booking->customer_id,
                'checked_in_at' => now(),
                'recorded_by' => $scannedBy->id,
            ]);

            $this->recordCheckIn($booking, $checkIn, __(':reference checked in.', ['reference' => $booking->reference]));

            return $checkIn;
        });
    }

    /**
     * Check the guest in at the tour stop whose code they scanned, on their
     * own booking with that tour. Once per stop per booking.
     *
     * @throws ValidationException for a bad code, a booking that is not theirs or not for this tour, or one that cannot be checked in
     */
    public function checkInAtStop(Booking $booking, string $token, User $customer): CheckIn
    {
        $stopId = $this->signer->stopIdFrom($token);

        return DB::transaction(function () use ($booking, $stopId, $customer): CheckIn {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->customer_id !== $customer->id) {
                throw ValidationException::withMessages(['booking' => __('That booking is not yours.')]);
            }

            $stop = ItineraryStop::query()->find($stopId);

            if ($stop === null) {
                throw ValidationException::withMessages(['qr' => __('This QR code is not valid here.')]);
            }

            if (! $locked->lines()->where('item_id', $stop->item_id)->exists()) {
                throw ValidationException::withMessages(['qr' => __(':stop is not on :reference.', ['stop' => $stop->location, 'reference' => $locked->reference])]);
            }

            $this->ensureCanCheckIn($locked);

            if ($locked->checkIns()->where('itinerary_stop_id', $stop->id)->exists()) {
                throw ValidationException::withMessages(['qr' => __('You already checked in at :stop.', ['stop' => $stop->location])]);
            }

            $checkIn = $locked->checkIns()->create([
                'itinerary_stop_id' => $stop->id,
                'stop_label' => $stop->location,
                'customer_id' => $locked->customer_id,
                'checked_in_at' => now(),
            ]);

            $this->recordCheckIn($locked, $checkIn, __(':reference checked in at :stop.', ['reference' => $locked->reference, 'stop' => $stop->location]));

            return $checkIn;
        });
    }

    /**
     * Mark by hand that the guest was served, checked in by QR or not.
     *
     * @throws ValidationException for a booking that is not approved, already finished, or not yet started
     */
    public function markCompleted(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking): Booking {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->ensureApproved($locked, __('completed'));

            if (! in_array($locked->fulfilment_status, [FulfilmentStatus::NotStarted, FulfilmentStatus::CheckedIn], true)) {
                throw ValidationException::withMessages(['booking' => __(':reference is already :status.', [
                    'reference' => $locked->reference,
                    'status' => mb_strtolower($locked->fulfilment_status->label()),
                ])]);
            }

            $this->ensureStarted($locked);

            return $this->finish($locked, FulfilmentStatus::Completed, AuditAction::BookingCompleted, __(':reference marked completed.', ['reference' => $locked->reference]));
        });
    }

    /**
     * Mark by hand that the guest never came. A guest who checked in was not
     * a no-show.
     *
     * @throws ValidationException for a booking that is not approved, checked in or finished, or not yet started
     */
    public function markNoShow(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking): Booking {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->ensureApproved($locked, __('marked a no-show'));

            if ($locked->fulfilment_status !== FulfilmentStatus::NotStarted) {
                throw ValidationException::withMessages(['booking' => __(':reference is :status, so the guest was not a no-show.', [
                    'reference' => $locked->reference,
                    'status' => mb_strtolower($locked->fulfilment_status->label()),
                ])]);
            }

            $this->ensureStarted($locked);

            return $this->finish($locked, FulfilmentStatus::NoShow, AuditAction::BookingMarkedNoShow, __(':reference marked a no-show.', ['reference' => $locked->reference]));
        });
    }

    /**
     * Complete a checked-in booking once its dates are over: the day after its
     * last line ends. Run daily for every agency (`bookings:complete-finished`).
     *
     * Checked again under the lock, so a booking staff marked by hand since it
     * was picked up is left as it is. A booking with no end date waits for
     * staff.
     *
     * @return bool whether the booking was completed
     */
    public function completeIfOver(Booking $booking): bool
    {
        return DB::transaction(function () use ($booking): bool {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $endsOn = $locked->lines()->max('ends_on');

            if ($locked->approval_status !== BookingApprovalStatus::Approved
                || $locked->fulfilment_status !== FulfilmentStatus::CheckedIn
                || ! is_string($endsOn)
                || ! today()->gt(Carbon::parse($endsOn))) {
                return false;
            }

            $this->finish($locked, FulfilmentStatus::Completed, AuditAction::BookingCompleted, __(':reference completed: its last day was :date.', [
                'reference' => $locked->reference,
                'date' => Carbon::parse($endsOn)->toFormattedDateString(),
            ]));

            return true;
        });
    }

    /**
     * Record the check-in, moving the booking to CheckedIn on its first.
     */
    private function recordCheckIn(Booking $booking, CheckIn $checkIn, string $description): void
    {
        $before = $booking->fulfilment_status;

        if ($before === FulfilmentStatus::NotStarted) {
            $booking->forceFill(['fulfilment_status' => FulfilmentStatus::CheckedIn])->save();
        }

        $this->recordAuditEvent(
            AuditAction::CheckInRecorded,
            $booking,
            $description,
            $before === $booking->fulfilment_status ? null : ['fulfilment_status' => ['old' => $before->value, 'new' => $booking->fulfilment_status->value]],
            ['check_in_id' => $checkIn->id, 'itinerary_stop_id' => $checkIn->itinerary_stop_id],
        );
    }

    private function finish(Booking $booking, FulfilmentStatus $status, AuditAction $action, string $description): Booking
    {
        $before = $booking->fulfilment_status;
        $booking->forceFill(['fulfilment_status' => $status])->save();

        $this->recordAuditEvent($action, $booking, $description, [
            'fulfilment_status' => ['old' => $before->value, 'new' => $status->value],
        ]);

        return $booking;
    }

    /**
     * @throws ValidationException
     */
    private function ensureCanCheckIn(Booking $booking): void
    {
        $this->ensureApproved($booking, __('checked in'));

        if (! in_array($booking->fulfilment_status, [FulfilmentStatus::NotStarted, FulfilmentStatus::CheckedIn], true)) {
            throw ValidationException::withMessages(['booking' => __(':reference is :status, so it cannot be checked in.', [
                'reference' => $booking->reference,
                'status' => mb_strtolower($booking->fulfilment_status->label()),
            ])]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureApproved(Booking $booking, string $action): void
    {
        if ($booking->approval_status !== BookingApprovalStatus::Approved) {
            throw ValidationException::withMessages(['booking' => __(':reference is :status, so it cannot be :action.', [
                'reference' => $booking->reference,
                'status' => mb_strtolower($booking->approval_status->label()),
                'action' => $action,
            ])]);
        }
    }

    /**
     * A booking cannot be finished before its first day.
     *
     * @throws ValidationException
     */
    private function ensureStarted(Booking $booking): void
    {
        $startsOn = $booking->lines()->min('starts_on');

        if (is_string($startsOn) && today()->lt(Carbon::parse($startsOn))) {
            throw ValidationException::withMessages(['booking' => __(':reference does not start until :date.', [
                'reference' => $booking->reference,
                'date' => Carbon::parse($startsOn)->toFormattedDateString(),
            ])]);
        }
    }
}
