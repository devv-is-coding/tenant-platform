<?php

namespace App\Policies;

use App\Models\SubscriptionPlan;
use App\Models\User;

class SubscriptionPlanPolicy
{
    /**
     * Determine whether the user can see the platform's price list.
     */
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can add a plan to the price list.
     */
    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can rename or reprice a plan.
     */
    public function update(User $user, SubscriptionPlan $subscriptionPlan): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can withdraw a plan from the price list.
     */
    public function delete(User $user, SubscriptionPlan $subscriptionPlan): bool
    {
        return $user->isSuperAdmin();
    }
}
