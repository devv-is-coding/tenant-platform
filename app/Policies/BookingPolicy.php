<?php

namespace App\Policies;

use App\Enums\PlanFeature;
use App\Models\Booking;
use App\Models\User;

/**
 * The booking approval workflow (TM-2): all of the agency's staff see its
 * bookings; its Managers and its Admin approve, reject and cancel them.
 *
 * Approving and rejecting need Bookings. Cancelling never does: an agency
 * that lost the feature must still be able to release a customer's hold.
 * AgencyPolicy refuses every one of them while the agency is read-only.
 */
class BookingPolicy extends AgencyPolicy
{
    /**
     * Get the plan feature bookings belong to.
     */
    protected function feature(): ?PlanFeature
    {
        return PlanFeature::Bookings;
    }

    /**
     * Get the plan feature one ability needs: cancelling needs none.
     */
    protected function featureFor(string $ability): ?PlanFeature
    {
        return $ability === 'cancel' ? null : $this->feature();
    }

    /**
     * Determine whether the user can list the agency's bookings.
     */
    public function viewAny(User $user): bool
    {
        return $this->isStaffOfCurrentAgency($user);
    }

    /**
     * Determine whether the user can approve or reject a booking.
     */
    public function decide(User $user, Booking $booking): bool
    {
        return $this->isDeciderOfCurrentAgency($user);
    }

    /**
     * Determine whether the user can cancel a booking.
     */
    public function cancel(User $user, Booking $booking): bool
    {
        return $this->isDeciderOfCurrentAgency($user);
    }
}
