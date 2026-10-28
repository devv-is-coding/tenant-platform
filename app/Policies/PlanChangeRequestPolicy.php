<?php

namespace App\Policies;

use App\Enums\PlanChangeRequestStatus;
use App\Enums\Role;
use App\Models\PlanChangeRequest;
use App\Models\Tenant;
use App\Models\User;

/**
 * Agencies ask for plans; super admins decide. Never gated by the agency's
 * plan or status: asking for a plan is how an agency without one gets one.
 */
class PlanChangeRequestPolicy
{
    /**
     * Determine whether the user can see every agency's plan requests.
     */
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can ask for a plan for the agency. Only the
     * agency's own admin may, and only for that agency.
     */
    public function create(User $user, Tenant $tenant): bool
    {
        return $user->role === Role::Admin && $user->tenant_id === $tenant->id;
    }

    /**
     * Determine whether the user can withdraw the request: the asking
     * agency's admin, while it is still pending.
     */
    public function withdraw(User $user, PlanChangeRequest $planChangeRequest): bool
    {
        return $user->role === Role::Admin
            && $user->tenant_id === $planChangeRequest->tenant_id
            && $planChangeRequest->status === PlanChangeRequestStatus::Pending;
    }

    /**
     * Determine whether the user can approve or decline the request.
     */
    public function decide(User $user, PlanChangeRequest $planChangeRequest): bool
    {
        return $user->isSuperAdmin();
    }
}
