<?php

namespace App\Policies;

use App\Models\SubscriptionInvoice;
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

    /**
     * Determine whether the user can record a payment received by hand, such
     * as a bank transfer, against the invoice.
     */
    public function recordPayment(User $user, SubscriptionInvoice $subscriptionInvoice): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can delete the invoice, with its payments.
     */
    public function delete(User $user, SubscriptionInvoice $subscriptionInvoice): bool
    {
        return $user->isSuperAdmin();
    }
}
