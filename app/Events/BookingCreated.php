<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer booked with the agency (BookingService::create()).
 *
 * Dispatched once the transaction it happened in commits, so a change that
 * rolls back notifies nobody. WriteNotifications turns it into the feed.
 */
class BookingCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Booking $booking) {}
}
