<?php

namespace App\Policies;

use App\Enums\PlanFeature;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Two sides of user accounts.
 *
 * The console's: the super admin deletes and restores an agency's accounts
 * from the central domain, outside any agency, so AgencyPolicy's rules do
 * not apply to those abilities.
 *
 * The agency's: its Admin creates, edits, deactivates and reactivates its
 * managers and staff, never an admin, a customer or anyone at another
 * agency. These go through AgencyPolicy: creating, editing and reactivating
 * need the Team feature and an agency that is not read-only. Deactivating is
 * a security action, refused by neither, so any agency can lock out a leaver.
 */
class UserPolicy extends AgencyPolicy
{
    /**
     * Get the plan feature the agency's team accounts belong to.
     */
    protected function feature(): ?PlanFeature
    {
        return PlanFeature::Team;
    }

    /**
     * Get the abilities that take access away.
     *
     * @return list<string>
     */
    protected function securityAbilities(): array
    {
        return ['deactivate'];
    }

    /**
     * Leave the console's abilities to their own rules, and apply the
     * agency's status and tier to the rest.
     */
    public function before(User $user, string $ability): ?Response
    {
        if (in_array($ability, ['delete', 'restore'], true)) {
            return null;
        }

        return parent::before($user, $ability);
    }

    /**
     * Determine whether the user can delete an agency's user account. A super
     * admin account is never deleted from an agency's page.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->isSuperAdmin() && ! $model->isSuperAdmin();
    }

    /**
     * Determine whether the user can bring back a deleted agency user account.
     */
    public function restore(User $user, User $model): bool
    {
        return $user->isSuperAdmin() && ! $model->isSuperAdmin();
    }

    /**
     * Determine whether the user can add a manager or staff member to the
     * agency's team.
     */
    public function create(User $user): bool
    {
        return $this->isAdminOfCurrentAgency($user);
    }

    /**
     * Determine whether the user can change a team member's details or role.
     */
    public function update(User $user, User $model): bool
    {
        return $this->managesTeamMember($user, $model);
    }

    /**
     * Determine whether the user can stop a team member signing in.
     */
    public function deactivate(User $user, User $model): bool
    {
        return $this->managesTeamMember($user, $model) && $model->is_active;
    }

    /**
     * Determine whether the user can let a deactivated team member sign in
     * again.
     */
    public function reactivate(User $user, User $model): bool
    {
        return $this->managesTeamMember($user, $model) && ! $model->is_active;
    }

    /**
     * Determine if the user is an admin of the agency and the model one of
     * its managers or staff, whose account still exists.
     */
    private function managesTeamMember(User $user, User $model): bool
    {
        return $this->isAdminOfCurrentAgency($user)
            && $model->tenant_id === $user->tenant_id
            && in_array($model->role, [Role::Manager, Role::Staff], true)
            && ! $model->trashed();
    }

    /**
     * Determine if the user is an admin of the agency of the current request.
     */
    private function isAdminOfCurrentAgency(User $user): bool
    {
        return $user->role === Role::Admin
            && tenancy()->initialized
            && $user->tenant_id === tenant('id');
    }
}
