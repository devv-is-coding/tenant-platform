<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer signed up in the agency's app. Dispatched by the customer API's
 * sign-up, not built yet.
 *
 * Dispatched once the transaction it happened in commits, so a change that
 * rolls back notifies nobody. WriteNotifications turns it into the feed.
 */
class CustomerRegistered implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public User $customer) {}
}
