<?php

namespace App\Policies;

use App\Models\User;

class PlanFeatureSettingPolicy
{
    /**
     * Determine whether the user can see which tier each feature starts at.
     */
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can move a feature to another tier.
     */
    public function update(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
