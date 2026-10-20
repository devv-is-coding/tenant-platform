<?php

namespace App\Policies;

use App\Models\User;

class SubscriptionInvoicePolicy
{
    /**
     * Determine whether the user can browse the platform's invoices across
     * agencies, such as the overdue list.
     */
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
