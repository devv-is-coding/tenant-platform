<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A manager approved the booking. Dispatched by the booking approval workflow
 * (TM-2), not built yet.
 *
 * Dispatched once the transaction it happened in commits, so a change that
 * rolls back notifies nobody. WriteNotifications turns it into the feed.
 */
class BookingApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Booking $booking) {}
}
