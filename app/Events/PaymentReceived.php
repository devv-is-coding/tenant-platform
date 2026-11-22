<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money came in against a booking: cash or a transfer staff recorded, or a
 * card payment Stripe confirmed (PaymentService).
 *
 * Dispatched once the transaction it happened in commits, so a change that
 * rolls back notifies nobody. WriteNotifications turns it into the feed.
 */
class PaymentReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Payment $payment) {}
}
