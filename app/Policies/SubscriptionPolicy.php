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

    /**
     * Determine whether the user can bring back a deleted subscription, with
     * the invoices and payments deleted along with it.
     */
    public function restore(User $user, Subscription $subscription): bool
    {
        return $user->isSuperAdmin();
    }
}
