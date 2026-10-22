<?php

namespace App\Enums;

/**
 * Backed by the `restore_requests.status` tinyint column.
 *
 * An agency admin asks for a deleted record back; a super admin restores it or
 * declines. Only Pending is open to a decision.
 */
enum RestoreRequestStatus: int
{
    case Pending = 1;
    case Restored = 2;
    case Declined = 3;

    /**
     * Get the human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Restored => __('Restored'),
            self::Declined => __('Declined'),
        };
    }

    /**
     * Get the Flux badge color the console shows the status in.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Restored => 'green',
            self::Declined => 'zinc',
        };
    }
}
