<?php

namespace App\Enums;

/**
 * Backed by the `subscription_invoices.status` tinyint column.
 *
 * Derived from the invoice's amounts by a pure function, never assigned - the
 * same rule as a booking's payment status, so the status cannot contradict the
 * payment ledger beneath it.
 *
 * Note what is deliberately absent: there is no Overdue case. Overdue is a
 * function of today's date (`status is not Paid AND due_on < today`), so storing
 * it would require a nightly job to flip it and would drift the moment that job
 * failed. A status that depends on the clock does not belong in a column.
 */
enum SubscriptionInvoiceStatus: int
{
    case Open = 1;
    case PartiallyPaid = 2;
    case Paid = 3;
    case Void = 4;

    /**
     * Derive the status of an invoice that has not been voided from what it
     * charges and what has been paid against it.
     *
     * Void is never derived: it is the platform withdrawing the charge, a
     * decision rather than a fact about the money.
     */
    public static function fromAmounts(int $amountMinor, int $amountPaidMinor): self
    {
        return match (true) {
            $amountPaidMinor <= 0 => self::Open,
            $amountPaidMinor < $amountMinor => self::PartiallyPaid,
            default => self::Paid,
        };
    }

    /**
     * Get the human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::PartiallyPaid => __('Partially paid'),
            self::Paid => __('Paid'),
            self::Void => __('Void'),
        };
    }

    /**
     * Get the Flux badge color the console shows the status in.
     */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'sky',
            self::PartiallyPaid => 'amber',
            self::Paid => 'green',
            self::Void => 'zinc',
        };
    }

    /**
     * Determine if the invoice still expects money.
     *
     * A void invoice does not, regardless of its balance - the platform has
     * withdrawn the charge rather than forgiven it.
     */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Open, self::PartiallyPaid], true);
    }

    /**
     * Determine if the invoice may still accept a payment.
     */
    public function acceptsPayment(): bool
    {
        return $this->isOutstanding();
    }

    /**
     * Determine if the invoice counts toward what the agency owes the platform.
     */
    public function countsTowardBalance(): bool
    {
        return $this !== self::Void;
    }
}
