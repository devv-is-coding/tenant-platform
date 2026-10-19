<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    /**
     * Determine whether the user can browse the agency directory.
     */
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can view an agency's platform record: its
     * users, subscription and platform audit trail - never its operational data.
     */
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can onboard a new agency.
     */
    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can activate, suspend or reactivate an agency.
     */
    public function changeStatus(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdmin();
    }
}
