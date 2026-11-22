<?php

namespace App\Events;

use App\Models\Refund;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A manager rejected a refund, with the reason on it
 * (RefundService::reject()).
 *
 * Dispatched once the transaction it happened in commits, so a change that
 * rolls back notifies nobody. WriteNotifications turns it into the feed.
 */
class RefundRejected implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Refund $refund) {}
}
