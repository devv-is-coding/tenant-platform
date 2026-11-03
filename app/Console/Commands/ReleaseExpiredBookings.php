<?php

namespace App\Console\Commands;

use App\Concerns\RunsInTenant;
use App\Enums\BookingApprovalStatus;
use App\Enums\BookingPaymentStatus;
use App\Models\Booking;
use App\Services\Booking\BookingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookings:release-expired')]
#[Description('Expire unconfirmed, unpaid bookings past their hold in every agency, freeing the units they held')]
class ReleaseExpiredBookings extends Command
{
    use RunsInTenant;

    /**
     * Execute the console command.
     *
     * A booking holds its units from the moment it is made, so one nobody
     * confirms or pays would block them for good. Only a booking still pending
     * and unpaid is touched (`BookingService::isLapsedHold()`): an approved or
     * paid booking's `expires_at` no longer means anything.
     */
    public function handle(BookingService $bookings): int
    {
        $expired = 0;

        $this->runInEachTenant(function () use ($bookings, &$expired): void {
            Booking::query()
                ->where('approval_status', BookingApprovalStatus::Pending)
                ->where('payment_status', BookingPaymentStatus::Unpaid)
                ->where('expires_at', '<=', now())
                ->each(function (Booking $booking) use ($bookings, &$expired): void {
                    $expired += (int) $bookings->expire($booking);
                });
        });

        $this->components->info("Expired {$expired} booking(s).");

        return self::SUCCESS;
    }
}
