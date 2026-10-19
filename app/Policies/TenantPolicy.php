<?php

namespace App\Policies;

use App\Models\User;

class TenantPolicy
{
    /**
     * Determine whether the user can onboard a new agency.
     */
    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
