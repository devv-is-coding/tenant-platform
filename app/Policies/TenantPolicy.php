<?php

namespace App\Policies;

use App\Enums\Role;
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
     * Determine whether the user can assign the agency an admin or revoke one.
     */
    public function manageAdmins(User $user, Tenant $tenant): bool
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

    /*
    | The agency's own side: what its staff see of their agency on its domain.
    | Never the super admin, who sees agencies only through the console, and
    | never the agency's customers.
    */

    /**
     * Determine whether the user can open the agency's dashboard: any of its
     * admins, managers or staff, whatever its status or plan.
     */
    public function viewAgencyDashboard(User $user, Tenant $tenant): bool
    {
        return $this->isStaffOf($user, $tenant);
    }

    /**
     * Determine whether the user can see the agency's plan tier and
     * subscription status: its admins, who are the ones who can act on them.
     */
    public function viewAgencyPlan(User $user, Tenant $tenant): bool
    {
        return $this->isStaffOf($user, $tenant) && $user->role === Role::Admin;
    }

    /**
     * Determine whether the user can see who is on the agency's team: its
     * admins, who manage it.
     */
    public function viewAgencyTeam(User $user, Tenant $tenant): bool
    {
        return $this->isStaffOf($user, $tenant) && $user->role === Role::Admin;
    }

    /**
     * Determine whether the user can see the agency's deleted records and the
     * restore requests it has sent: its admins, who are the ones who may ask
     * (RestoreRequestPolicy::create). In any status and on any plan.
     */
    public function viewAgencyRestoreRequests(User $user, Tenant $tenant): bool
    {
        return $this->isStaffOf($user, $tenant) && $user->role === Role::Admin;
    }

    /**
     * Determine whether the user can see the agency's plan with its price,
     * its invoices and its payments: its admins only, so managers and staff
     * never see what the agency pays. In any status and on any plan.
     */
    public function viewBilling(User $user, Tenant $tenant): bool
    {
        return $this->isStaffOf($user, $tenant) && $user->role === Role::Admin;
    }

    /**
     * Determine if the user is one of the agency's admins, managers or staff.
     */
    private function isStaffOf(User $user, Tenant $tenant): bool
    {
        return $user->role->isTenantRole() && $user->tenant_id === $tenant->id;
    }
}
