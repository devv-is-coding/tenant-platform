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
        return $user->isSuperAdmin() && ! $tenant->trashed();
    }

    /**
     * Determine whether the user can put an agency on a subscription plan or
     * move it to a different one.
     */
    public function manageSubscription(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdmin() && ! $tenant->trashed();
    }

    /**
     * Determine whether the user can delete an agency, with its users, domains
     * and billing records.
     */
    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdmin() && ! $tenant->trashed();
    }

    /**
     * Determine whether the user can bring back a deleted agency, with what
     * was deleted along with it.
     */
    public function restore(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdmin() && $tenant->trashed();
    }
}
