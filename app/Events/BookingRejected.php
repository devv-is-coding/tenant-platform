<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A manager rejected the booking, with the reason on it. Dispatched by
 * BookingService::reject() (TM-2).
 *
 * Dispatched once the transaction it happened in commits, so a change that
 * rolls back notifies nobody. WriteNotifications turns it into the feed.
 */
class BookingRejected implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Booking $booking) {}
}
