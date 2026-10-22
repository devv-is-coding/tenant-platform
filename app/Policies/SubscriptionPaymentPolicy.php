<?php

namespace App\Policies;

use App\Models\SubscriptionPayment;
use App\Models\User;

class SubscriptionPaymentPolicy
{
    /**
     * Determine whether the user can delete a payment, such as one recorded by
     * mistake.
     */
    public function delete(User $user, SubscriptionPayment $subscriptionPayment): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can bring back a deleted payment.
     */
    public function restore(User $user, SubscriptionPayment $subscriptionPayment): bool
    {
        return $user->isSuperAdmin();
    }
}
