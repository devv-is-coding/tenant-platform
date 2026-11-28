<?php

namespace App\Policies;

use App\Enums\PlanFeature;
use App\Enums\Role;
use App\Models\Item;
use App\Models\User;

/**
 * The listing approval workflow (TM-1): any of the agency's staff sends a
 * listing for approval; its Managers and its Admin (who can do everything a
 * manager can) approve, reject, publish and unpublish. Staff never decide,
 * so nobody on staff can approve their own listing; a manager may approve a
 * listing they wrote themselves.
 *
 * Every step needs Catalog, and AgencyPolicy refuses them all while the
 * agency is read-only. Reading the catalog is never gated.
 */
class ItemPolicy extends AgencyPolicy
{
    /**
     * Get the plan feature listings belong to.
     */
    protected function feature(): ?PlanFeature
    {
        return PlanFeature::Catalog;
    }

    /**
     * Get the abilities that only read: the approval queue is one.
     *
     * @return list<string>
     */
    protected function readAbilities(): array
    {
        return [...parent::readAbilities(), 'viewApprovalQueue'];
    }

    /**
     * Determine whether the user can list the agency's listings.
     */
    public function viewAny(User $user): bool
    {
        return $this->isStaffOfCurrentAgency($user);
    }

    /**
     * Determine whether the user can open the listings waiting for a
     * decision or for publishing.
     */
    public function viewApprovalQueue(User $user): bool
    {
        return $this->isDeciderOfCurrentAgency($user);
    }

    /**
     * Determine whether the user can send a listing for approval.
     */
    public function submit(User $user, Item $item): bool
    {
        return $this->isStaffOfCurrentAgency($user);
    }

    /**
     * Determine whether the user can approve or reject a listing.
     */
    public function decide(User $user, Item $item): bool
    {
        return $this->isDeciderOfCurrentAgency($user);
    }

    /**
     * Determine whether the user can publish an approved listing, or take a
     * published one back off sale.
     */
    public function publish(User $user, Item $item): bool
    {
        return $this->isDeciderOfCurrentAgency($user);
    }

    /**
     * Determine if the user is an Admin, Manager or Staff member of the
     * agency of the current request.
     */
    private function isStaffOfCurrentAgency(User $user): bool
    {
        return $user->role->isTenantRole()
            && tenancy()->initialized
            && $user->tenant_id === tenant('id');
    }

    /**
     * Determine if the user is an Admin or Manager of the agency of the
     * current request.
     */
    private function isDeciderOfCurrentAgency(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Manager], true)
            && $this->isStaffOfCurrentAgency($user);
    }
}
