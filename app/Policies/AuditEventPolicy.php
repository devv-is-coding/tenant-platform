<?php

namespace App\Policies;

use App\Enums\PlanFeature;
use App\Enums\Role;
use App\Models\User;

/**
 * The agency's own audit log is its Admin's to read, on every tier: it is the
 * agency's record, which a lapsed agency keeps. AuditLog gates only what is
 * added to the log later (exports, retention settings); reading never is.
 */
class AuditEventPolicy extends AgencyPolicy
{
    /**
     * Get the plan feature the log's extras belong to.
     */
    protected function feature(): ?PlanFeature
    {
        return PlanFeature::AuditLog;
    }

    /**
     * Determine whether the user can read the agency's audit log.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === Role::Admin
            && tenancy()->initialized
            && $user->tenant_id === tenant('id');
    }
}
