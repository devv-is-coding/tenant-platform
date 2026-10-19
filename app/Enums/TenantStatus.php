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

    /**
     * Determine if a super admin may move an agency from this status to the
     * given one. Paused and Closed are not reachable yet, so nothing leads
     * into or out of them.
     */
    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Pending, self::Suspended => $status === self::Active,
            self::Active => $status === self::Suspended,
            self::Paused, self::Closed => false,
        };
    }

    /**
     * Determine if the agency's staff may look but not change anything.
     *
     * Read-only rather than locked out: a suspended or paused agency still
     * owes its customers the travel it already sold, and its staff need their
     * records to honour it.
     */
    public function isReadOnly(): bool
    {
        return in_array($this, [self::Paused, self::Suspended], true);
    }

    /**
     * Get the Flux badge color the console shows the status in.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Active => 'green',
            self::Paused => 'sky',
            self::Suspended => 'red',
            self::Closed => 'zinc',
        };
    }
}
