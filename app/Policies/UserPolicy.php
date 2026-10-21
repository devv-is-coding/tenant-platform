<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can delete an agency's user account. A super
     * admin account is never deleted from an agency's page.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->isSuperAdmin() && ! $model->isSuperAdmin();
    }
}
