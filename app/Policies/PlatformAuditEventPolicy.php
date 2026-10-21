<?php

namespace App\Policies;

use App\Models\User;

class PlatformAuditEventPolicy
{
    /**
     * Determine whether the user can read the platform's audit log, across
     * every agency.
     */
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
