<?php

namespace App\Events;

use App\Models\Refund;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A manager approved a refund, for what was asked or less
 * (RefundService::approve()).
 *
 * Dispatched once the transaction it happened in commits, so a change that
 * rolls back notifies nobody. WriteNotifications turns it into the feed.
 */
class RefundApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Refund $refund) {}
}
