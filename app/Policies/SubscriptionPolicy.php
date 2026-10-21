<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    /**
     * Determine whether the user can delete a subscription, with its invoices
     * and payments.
     */
    public function delete(User $user, Subscription $subscription): bool
    {
        return $user->isSuperAdmin();
    }
}
