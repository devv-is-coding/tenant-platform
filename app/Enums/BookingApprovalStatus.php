<?php

namespace App\Enums;

/**
 * Backed by the `bookings.approval_status` tinyint column.
 *
 * This is the manager's decision axis, and it is genuinely independent of the
 * money axis in `bookings.payment_status`. The legacy proved the independence
 * was intentional rather than a bug: a rejected booking keeps its payment
 * status at Unpaid. That two-axis design is preserved.
 */
enum BookingApprovalStatus: int
{
    case Pending = 0;
    case Approved = 1;
    case Rejected = 2;
    case Cancelled = 3;
    case Expired = 4;

    /**
     * Get the human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending approval'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
            self::Cancelled => __('Cancelled'),
            self::Expired => __('Expired'),
        };
    }

    /**
     * Get the Flux badge color the status is shown in.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'green',
            self::Rejected => 'red',
            self::Cancelled, self::Expired => 'zinc',
        };
    }

    /**
     * Determine if the booking should release the inventory it is holding.
     *
     * Rejecting releases inventory; approving does not. Release hard-deletes the
     * allocation rows, because a soft-deleted row still occupies its slot in the
     * unique index and would block resale.
     */
    public function releasesInventory(): bool
    {
        return in_array($this, [self::Rejected, self::Cancelled, self::Expired], true);
    }

    /**
     * Determine if the status requires a reason to be recorded.
     */
    public function requiresReason(): bool
    {
        return $this === self::Rejected;
    }

    /**
     * Determine if the booking is still live and holding inventory.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Approved], true);
    }
}
