<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user's profile details were changed. Dispatched by the customer API's
 * profile endpoint, not built yet.
 *
 * Dispatched once the transaction it happened in commits, so a change that
 * rolls back notifies nobody. WriteNotifications turns it into the feed.
 */
class ProfileUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public User $user) {}
}
