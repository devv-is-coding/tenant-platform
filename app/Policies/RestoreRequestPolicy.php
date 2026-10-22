<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\RestoreRequest;
use App\Models\Tenant;
use App\Models\User;

class RestoreRequestPolicy
{
    /**
     * Determine whether the user can see every agency's restore requests.
     */
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can ask for one of the agency's deleted
     * records back. Only the agency's own admin may, and only for that agency.
     */
    public function create(User $user, Tenant $tenant): bool
    {
        return $user->role === Role::Admin && $user->tenant_id === $tenant->id;
    }

    /**
     * Determine whether the user can restore or decline the request.
     */
    public function decide(User $user, RestoreRequest $restoreRequest): bool
    {
        return $user->isSuperAdmin();
    }
}
