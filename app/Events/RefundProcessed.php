<?php

namespace App\Events;

use App\Models\Refund;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A refund was paid out, by hand or through Stripe (RefundService::settle()).
 *
 * Dispatched once the transaction it happened in commits, so a change that
 * rolls back notifies nobody. WriteNotifications turns it into the feed.
 */
class RefundProcessed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Refund $refund) {}
}
