<?php

namespace App\Events;

use App\Models\Item;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A manager approved or rejected a listing staff submitted; the item's status
 * says which. Dispatched by the listing approval workflow (TM-1), not built
 * yet.
 *
 * Dispatched once the transaction it happened in commits, so a change that
 * rolls back notifies nobody. WriteNotifications turns it into the feed.
 */
class ListingDecided implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Item $item) {}
}
