<?php

namespace App\Services\Payment;

use App\Concerns\RecordsAuditEvents;
use App\Enums\AuditAction;
use App\Enums\BookingApprovalStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\RefundStatus;
use App\Enums\RefundType;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiErrorException;

/**
 * A refund's whole life, ported from the legacy's negotiation, with as many
 * refunds per booking as it needs (defect 9): the customer asks with a reason,
 * a manager approves (all of it or less) or rejects with a reason, and only
 * then is it processed, by hand for cash and through Stripe for a card.
 *
 * **Never more back than came in.** Every step locks the booking's row and
 * checks against the ledger as it stands: what was paid, less what has been
 * refunded, less what other requests and approvals already count on. So two
 * refunds asked for at once cannot together exceed what was paid.
 *
 * **A full refund ends the booking.** Approving one that returns everything
 * still paid cancels the booking and hard-deletes its held units, free to
 * sell again at once (a soft-deleted unit would still hold its slot). A
 * partial refund (a goodwill credit, a dropped extra) leaves the booking and
 * its units as they are. A rejected refund changes nothing but itself.
 *
 * Who may decide is for the manager's page and its policy (TM-3); this
 * service keeps the money true whoever calls it.
 */
class RefundService
{
    use RecordsAuditEvents;

    public function __construct(private StripeGateway $gateway) {}

    /**
     * Ask for money back on a booking, optionally naming the payment it
     * reverses (a card payment must be named to be refunded through Stripe).
     *
     * @throws ValidationException for an amount, payment or reason the booking cannot take
     */
    public function request(Booking $booking, Money $amount, string $reason, User $requestedBy, ?Payment $payment = null): Refund
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Say why the refund is wanted.')]);
        }

        $this->ensureIsPositiveIn($amount, $booking);

        return DB::transaction(function () use ($booking, $amount, $reason, $requestedBy, $payment): Refund {
            $locked = $this->lock($booking);
            $this->ensureRefundable($locked, $amount->minor, $payment);

            $refund = $locked->refunds()->create([
                'payment_id' => $payment?->id,
                'type' => $this->typeFor($locked, $amount->minor),
                'status' => RefundStatus::Requested,
                'amount' => $amount,
                'reason' => trim($reason),
                'requested_by' => $requestedBy->id,
                'requested_at' => now(),
            ]);

            $this->recordAuditEvent(AuditAction::RefundRequested, $locked, __(':amount refund asked for on :reference.', [
                'amount' => $amount->format(),
                'reference' => $locked->reference,
            ]), context: ['refund_id' => $refund->id]);

            return $refund;
        });
    }

    /**
     * Approve a requested refund, for what was asked or less. A full refund
     * (everything still paid) cancels the booking and frees its units.
     *
     * @throws ValidationException for a refund no longer awaiting a decision, or an amount it cannot take
     */
    public function approve(Refund $refund, User $decidedBy, ?Money $amount = null): Refund
    {
        return DB::transaction(function () use ($refund, $decidedBy, $amount): Refund {
            [$booking, $locked] = $this->lockBoth($refund);
            $this->ensureStatus($locked, RefundStatus::Requested);

            $approved = $amount ?? $locked->amount;
            $this->ensureIsPositiveIn($approved, $booking);

            if ($approved->minor > $locked->amount_minor) {
                throw ValidationException::withMessages([
                    'amount' => __('Approve at most the :requested asked for.', ['requested' => $locked->amount->format()]),
                ]);
            }

            // The ledger may have moved since the request: check it as it is now.
            $refundable = $this->refundableMinor($booking, except: $locked);

            if ($approved->minor > $refundable) {
                throw ValidationException::withMessages([
                    'amount' => __('The amount is more than the :refundable that can still be refunded.', [
                        'refundable' => Money::ofMinor($refundable, $booking->currency)->format(),
                    ]),
                ]);
            }

            $type = $this->typeFor($booking, $approved->minor, except: $locked);

            $locked->forceFill([
                'amount' => $approved,
                'type' => $type,
                'status' => RefundStatus::Approved,
                'decided_by' => $decidedBy->id,
                'decided_at' => now(),
            ])->save();

            $released = $type === RefundType::Full ? $this->endBooking($booking) : 0;

            $this->recordAuditEvent(AuditAction::RefundApproved, $booking, __(':amount :type approved on :reference.', [
                'amount' => $approved->format(),
                'type' => mb_strtolower($type->label()),
                'reference' => $booking->reference,
            ]), context: ['refund_id' => $locked->id, 'released_units' => $released]);

            return $locked;
        });
    }

    /**
     * Reject a requested refund, with the reason the customer will be given.
     * The money and the inventory stay as they are.
     *
     * @throws ValidationException for a refund no longer awaiting a decision, or no reason
     */
    public function reject(Refund $refund, User $decidedBy, string $reason): Refund
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['decision_reason' => __('Give the customer a reason for the rejection.')]);
        }

        return DB::transaction(function () use ($refund, $decidedBy, $reason): Refund {
            [$booking, $locked] = $this->lockBoth($refund);
            $this->ensureStatus($locked, RefundStatus::Requested);

            $locked->forceFill([
                'status' => RefundStatus::Rejected,
                'decided_by' => $decidedBy->id,
                'decided_at' => now(),
                'decision_reason' => trim($reason),
            ])->save();

            $this->recordAuditEvent(AuditAction::RefundRejected, $booking, __(':amount refund rejected on :reference.', [
                'amount' => $locked->amount->format(),
                'reference' => $booking->reference,
            ]), context: ['refund_id' => $locked->id]);

            return $locked;
        });
    }

    /**
     * Pay an approved refund out. Cash (or no named payment) is handed back
     * by hand and recorded here; a card payment goes back through Stripe, and
     * is processed once Stripe says it succeeded, now or by webhook later.
     *
     * Stripe is called with no transaction open, then the outcome is written
     * under the locks; a refund settled meanwhile is left as it is.
     *
     * @throws ValidationException for a refund that is not approved
     * @throws ApiErrorException when Stripe refuses
     */
    public function process(Refund $refund): Refund
    {
        $this->ensureStatus($refund, RefundStatus::Approved);
        $payment = $refund->payment;

        if ($payment === null || ! $payment->isGatewaySettled()) {
            return $this->settle($refund, RefundStatus::Processed);
        }

        $stripeRefund = $this->gateway->refund($refund, $payment);

        return $this->settle($refund, match ($stripeRefund['status']) {
            'succeeded' => RefundStatus::Processed,
            'failed', 'canceled' => RefundStatus::Failed,
            default => RefundStatus::Approved,
        }, $stripeRefund['id']);
    }

    /**
     * Write a refund's outcome: Processed counts its money as returned (and
     * marks the payment it reverses), Failed leaves the money where it was,
     * Approved means Stripe is still working on it. Only an approved refund
     * moves; a second delivery of the same outcome changes nothing.
     */
    public function settle(Refund $refund, RefundStatus $outcome, ?string $stripeRefundId = null): Refund
    {
        return DB::transaction(function () use ($refund, $outcome, $stripeRefundId): Refund {
            [$booking, $locked] = $this->lockBoth($refund);

            if ($locked->status !== RefundStatus::Approved) {
                return $locked;
            }

            $locked->forceFill([
                'status' => $outcome,
                'stripe_refund_id' => $stripeRefundId ?? $locked->stripe_refund_id,
                'processed_at' => $outcome === RefundStatus::Processed ? now() : null,
            ])->save();

            if ($outcome !== RefundStatus::Processed) {
                return $locked;
            }

            $before = ['amount_refunded_minor' => $booking->amount_refunded_minor, 'payment_status' => $booking->payment_status->value];
            $booking->recomputeMoney();
            $this->markPaymentRefunded($locked->payment);

            $this->recordAuditEvent(AuditAction::RefundProcessed, $booking, __(':amount refunded on :reference.', [
                'amount' => $locked->amount->format(),
                'reference' => $booking->reference,
            ]), [
                'amount_refunded_minor' => ['old' => $before['amount_refunded_minor'], 'new' => $booking->amount_refunded_minor],
                'payment_status' => ['old' => $before['payment_status'], 'new' => $booking->payment_status->value],
            ], ['refund_id' => $locked->id, 'stripe_refund_id' => $locked->stripe_refund_id]);

            return $locked;
        });
    }

    /**
     * Get how much of the booking can still be refunded: what was paid, less
     * what has gone back, less what other refunds already count on.
     */
    public function refundableMinor(Booking $booking, ?Refund $except = null): int
    {
        $spokenFor = (int) $booking->refunds()->outstanding()
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->id))
            ->sum('amount_minor');

        return max(0, $booking->amount_paid_minor - $booking->amount_refunded_minor - $spokenFor);
    }

    /**
     * Cancel the booking a full refund ends, and hard-delete its units so
     * they can be sold again at once.
     *
     * @return int the units released
     */
    private function endBooking(Booking $booking): int
    {
        if ($booking->approval_status->isActive()) {
            $booking->forceFill(['approval_status' => BookingApprovalStatus::Cancelled, 'cancelled_at' => now()])->save();
        }

        return $booking->allocations()->delete();
    }

    /**
     * Mark the payment a processed refund reverses: Refunded once all of it
     * has gone back, PartiallyRefunded before. It still counts as paid; the
     * refund is what is subtracted.
     */
    private function markPaymentRefunded(?Payment $payment): void
    {
        if ($payment === null) {
            return;
        }

        $refundedMinor = (int) $payment->refunds()->countingAsRefunded()->sum('amount_minor');

        $payment->forceFill([
            'status' => $refundedMinor >= $payment->amount_minor ? PaymentRecordStatus::Refunded : PaymentRecordStatus::PartiallyRefunded,
        ])->save();
    }

    /**
     * Full when the amount is everything still refundable, so nothing paid
     * would be left with the agency; partial otherwise.
     */
    private function typeFor(Booking $booking, int $amountMinor, ?Refund $except = null): RefundType
    {
        return $amountMinor >= $this->refundableMinor($booking, $except) ? RefundType::Full : RefundType::Partial;
    }

    /**
     * @throws ValidationException
     */
    private function ensureRefundable(Booking $booking, int $amountMinor, ?Payment $payment): void
    {
        $refundable = $this->refundableMinor($booking);

        if ($amountMinor > $refundable) {
            throw ValidationException::withMessages([
                'amount' => __('The amount is more than the :refundable that can still be refunded.', [
                    'refundable' => Money::ofMinor($refundable, $booking->currency)->format(),
                ]),
            ]);
        }

        if ($payment === null) {
            return;
        }

        if ($payment->booking_id !== $booking->id || ! $payment->status->countsAsPaid()) {
            throw ValidationException::withMessages(['payment' => __('That payment was not received for :reference.', ['reference' => $booking->reference])]);
        }

        // What has gone back from this payment, or is on its way.
        $leftOnPayment = $payment->amount_minor - (int) $payment->refunds()
            ->whereIn('status', [RefundStatus::Requested, RefundStatus::Approved, RefundStatus::Processed])
            ->sum('amount_minor');

        if ($amountMinor > $leftOnPayment) {
            throw ValidationException::withMessages([
                'amount' => __('The amount is more than the :left left of payment :payment.', [
                    'left' => Money::ofMinor(max(0, $leftOnPayment), $booking->currency)->format(),
                    'payment' => $payment->reference,
                ]),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureStatus(Refund $refund, RefundStatus $expected): void
    {
        if ($refund->status !== $expected) {
            throw ValidationException::withMessages([
                'refund' => __('This refund is :status, not :expected.', [
                    'status' => mb_strtolower($refund->status->label()),
                    'expected' => mb_strtolower($expected->label()),
                ]),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsPositiveIn(Money $amount, Booking $booking): void
    {
        if ($amount->currency !== $booking->currency) {
            throw ValidationException::withMessages([
                'amount' => __(':reference was booked in :currency.', ['reference' => $booking->reference, 'currency' => $booking->currency]),
            ]);
        }

        if ($amount->minor <= 0) {
            throw ValidationException::withMessages(['amount' => __('The amount must be more than zero.')]);
        }
    }

    /**
     * Lock the booking's row, as payments do, so its ledger cannot move under us.
     */
    private function lock(Booking $booking): Booking
    {
        return Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Lock the refund's booking, then the refund, always in that order.
     *
     * @return array{0: Booking, 1: Refund}
     */
    private function lockBoth(Refund $refund): array
    {
        $booking = Booking::query()->whereKey($refund->booking_id)->lockForUpdate()->firstOrFail();

        return [$booking, Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail()];
    }
}
