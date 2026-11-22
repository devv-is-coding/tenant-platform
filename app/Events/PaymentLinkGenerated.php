<?php

namespace App\Events;

use App\Models\Booking;
use App\ValueObjects\Money;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A Stripe payment link was made for what the booking still owes
 * (StripeGateway::balanceInvoiceUrl()).
 *
 * Dispatched once the transaction it happened in commits, so a change that
 * rolls back notifies nobody. WriteNotifications turns it into the feed.
 */
class PaymentLinkGenerated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Booking $booking, public Money $balance) {}
}
