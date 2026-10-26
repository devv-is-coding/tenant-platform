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
     * given one.
     *
     * Nothing returns to Pending: going live is one-way. A closed agency comes
     * back only to Active, and only once it is on a plan again - which the
     * status change itself checks, since that is not a fact about statuses.
     */
    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Pending, self::Closed => $status === self::Active,
            self::Active => in_array($status, [self::Paused, self::Suspended, self::Closed], true),
            self::Paused => in_array($status, [self::Active, self::Suspended, self::Closed], true),
            self::Suspended => in_array($status, [self::Active, self::Closed], true),
        };
    }

    /**
     * Determine if moving an agency into this status must say why. Every
     * status that restricts an agency does: the reason is shown to its staff.
     */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Paused, self::Suspended, self::Closed], true);
    }

    /**
     * Determine if the agency's staff may sign in at all. A pending agency is
     * not live yet, so nobody can.
     */
    public function allowsStaffSignIn(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Determine if the agency's staff may look but not change anything.
     *
     * Read-only rather than locked out: a paused, suspended or closed agency
     * still owes its customers the travel it already sold, and its staff need
     * their records to honour it.
     */
    public function isReadOnly(): bool
    {
        return in_array($this, [self::Paused, self::Suspended, self::Closed], true);
    }

    /**
     * Get the reason a write is refused while the agency is read-only, the
     * same from the route guard and from a policy.
     */
    public function readOnlyNotice(): string
    {
        return __('This agency is :status, so its records are read-only.', [
            'status' => mb_strtolower($this->label()),
        ]);
    }

    /**
     * Determine if the agency's screens must mask customers' personal details
     * and money figures - bookings, payments, refunds, reports - until it
     * subscribes again. Staff still see that a booking exists, its reference,
     * dates and status, so they can honour it, but cannot work from it.
     *
     * Only Closed: a closed agency that wants its records back must return to
     * a plan. Every tenant-side screen that shows either kind of data must
     * honour this.
     */
    public function hidesCustomersAndMoney(): bool
    {
        return $this === self::Closed;
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
