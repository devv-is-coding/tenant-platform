<?php

namespace App\Policies;

use App\Enums\PlanFeature;
use App\Models\Refund;
use App\Models\User;

/**
 * Refund decisions (TM-3): the agency's Managers and its Admin see the
 * refunds asked for, approve them in full or in part or reject them with a
 * reason, then pay them out. Staff never decide.
 *
 * Deciding needs Bookings. Paying out through Stripe needs StripeRefunds;
 * handing money back by hand (cash, a bank transfer) is never gated, so any
 * tier can return what it owes. AgencyPolicy refuses all of them while the
 * agency is read-only.
 */
class RefundPolicy extends AgencyPolicy
{
    /**
     * Get the plan feature refunds belong to.
     */
    protected function feature(): ?PlanFeature
    {
        return PlanFeature::Bookings;
    }

    /**
     * Get the plan feature one ability needs: Stripe's step needs
     * StripeRefunds, and paying back by hand needs none.
     */
    protected function featureFor(string $ability): ?PlanFeature
    {
        return match ($ability) {
            'processThroughStripe' => PlanFeature::StripeRefunds,
            'processByHand' => null,
            default => $this->feature(),
        };
    }

    /**
     * Determine whether the user can list the agency's refunds.
     */
    public function viewAny(User $user): bool
    {
        return $this->isDeciderOfCurrentAgency($user);
    }

    /**
     * Determine whether the user can approve or reject a requested refund.
     */
    public function decide(User $user, Refund $refund): bool
    {
        return $this->isDeciderOfCurrentAgency($user);
    }

    /**
     * Determine whether the user can pay an approved refund out through Stripe.
     */
    public function processThroughStripe(User $user, Refund $refund): bool
    {
        return $this->isDeciderOfCurrentAgency($user);
    }

    /**
     * Determine whether the user can record an approved refund as handed back
     * another way.
     */
    public function processByHand(User $user, Refund $refund): bool
    {
        return $this->isDeciderOfCurrentAgency($user);
    }
}
