<?php

namespace App\Console\Commands;

use App\Concerns\RunsInTenant;
use App\Enums\BookingApprovalStatus;
use App\Enums\FulfilmentStatus;
use App\Models\Booking;
use App\Services\Fulfilment\FulfilmentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookings:complete-finished')]
#[Description('Complete checked-in bookings whose dates are over, in every agency')]
class CompleteFinishedBookings extends Command
{
    use RunsInTenant;

    /**
     * Execute the console command.
     *
     * A guest who checked in and whose trip or stay is over was served, and
     * completing the booking is what opens it to a review (TA-21). Each
     * booking is checked again under its lock (`FulfilmentService::completeIfOver()`).
     */
    public function handle(FulfilmentService $fulfilment): int
    {
        $completed = 0;

        $this->runInEachTenant(function () use ($fulfilment, &$completed): void {
            Booking::query()
                ->where('approval_status', BookingApprovalStatus::Approved)
                ->where('fulfilment_status', FulfilmentStatus::CheckedIn)
                ->each(function (Booking $booking) use ($fulfilment, &$completed): void {
                    $completed += (int) $fulfilment->completeIfOver($booking);
                });
        });

        $this->components->info("Completed {$completed} booking(s).");

        return self::SUCCESS;
    }
}
