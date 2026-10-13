<?php

namespace App\Enums;

/**
 * Backed by the `tenants.status` tinyint column: whether an agency may trade.
 *
 * Deliberately independent of SubscriptionStatus. A subscription can be PastDue
 * while the agency is still Active, because an unpaid invoice is a fact and
 * suspending access is a separate decision a super admin makes.
 *
 * Paused and Suspended grant the same access but are not interchangeable: Paused
 * is an agency-requested hiatus, Suspended is a finding of breach. Closed is a
 * status rather than a DELETE, so the agency's records survive the retention
 * window.
 *
 * There is no case 0, so a coerced empty value cannot land on a valid status.
 */
enum TenantStatus: int
{
    case Pending = 1;
    case Active = 2;
    case Paused = 3;
    case Suspended = 4;
    case Closed = 5;

    /**
     * Get the human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Active => __('Active'),
            self::Paused => __('Paused'),
            self::Suspended => __('Suspended'),
            self::Closed => __('Closed'),
        };
    }
}
