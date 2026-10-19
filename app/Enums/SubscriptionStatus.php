<?php

namespace App\Enums;

/**
 * Backed by the `subscriptions.status` tinyint column.
 *
 * This is the platform's commercial relationship with one agency, and it is
 * deliberately independent of `tenants.status`, which is whether that agency may
 * trade. The two form the same kind of orthogonal pair as a booking's approval
 * and payment axes: a subscription can be PastDue while the agency is still
 * fully Active, because an unpaid invoice is a fact and enforcement is a
 * separate decision.
 */
enum SubscriptionStatus: int
{
    case Trialing = 1;
    case Active = 2;
    case PastDue = 3;
    case Cancelled = 4;
    case Expired = 5;

    /**
     * Get the human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Trialing => __('Trialing'),
            self::Active => __('Active'),
            self::PastDue => __('Past due'),
            self::Cancelled => __('Cancelled'),
            self::Expired => __('Expired'),
        };
    }

    /**
     * Get the Flux badge color the console shows the status in.
     */
    public function color(): string
    {
        return match ($this) {
            self::Trialing => 'sky',
            self::Active => 'green',
            self::PastDue => 'red',
            self::Cancelled, self::Expired => 'zinc',
        };
    }

    /**
     * Determine if the platform should keep issuing invoices for this period.
     */
    public function isBillable(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }

    /**
     * Determine if the subscription still occupies the tenant's active slot.
     *
     * Only one subscription per tenant may be live at a time, which the
     * `active_for_tenant_id` unique key enforces.
     */
    public function isLive(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }

    /**
     * Determine if the platform's billing rules permit suspending the tenant.
     *
     * Permit, not require: suspension stays a deliberate act by the super admin,
     * never an automatic consequence of an unpaid invoice.
     */
    public function permitsEnforcement(): bool
    {
        return $this === self::PastDue;
    }
}
