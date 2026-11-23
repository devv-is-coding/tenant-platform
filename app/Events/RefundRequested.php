<?php

namespace App\Events;

use App\Models\Refund;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money back was asked for on a booking (RefundService::request()).
 *
 * Dispatched once the transaction it happened in commits, so a change that
 * rolls back notifies nobody. WriteNotifications turns it into the feed.
 */
class RefundRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Refund $refund) {}
}
