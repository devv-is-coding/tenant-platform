<?php

namespace App\Enums;

/**
 * Backed by the `plan_change_requests.status` tinyint column.
 *
 * An agency admin asks for a plan; a super admin approves (which puts the
 * agency on it) or declines. The admin can withdraw a request while it waits.
 * Only Pending is open to a decision.
 */
enum PlanChangeRequestStatus: int
{
    case Pending = 1;
    case Approved = 2;
    case Declined = 3;
    case Withdrawn = 4;

    /**
     * Get the human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Approved => __('Approved'),
            self::Declined => __('Declined'),
            self::Withdrawn => __('Withdrawn'),
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
            self::Declined, self::Withdrawn => 'zinc',
        };
    }
}
