<?php

namespace App\Services\Payment;

use App\Concerns\RecordsAuditEvents;
use App\Enums\AuditAction;
use App\Enums\BookingApprovalStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\RefundStatus;
use App\Enums\RefundType;
use App\Events\RefundApproved;
use App\Events\RefundProcessed;
use App\Events\RefundRejected;
use App\Events\RefundRequested;
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
 * still paid, after the other *approved* refunds (a mere request may still
 * be rejected, so it never makes another refund full), cancels the booking
 * and hard-deletes its held units, free to sell again at once (a soft-deleted
 * unit would still hold its slot). A partial refund (a goodwill credit, a
 * dropped extra) leaves the booking and its units as they are. A rejected
 * refund changes nothing but itself.
 *
 * **Every approved refund is paid out in the end.** Stripe can fail one, at
 * once or days after it succeeded: it is then Failed, still owed and still
 * counted against what can be refunded, and can be processed again through
 * Stripe or paid out by hand. A refund Stripe is still working on is asked
 * about, never created a second time.
 *
 * Who may decide is for the manager's refunds page and RefundPolicy (TM-3);
 * this service keeps the money true whoever calls it.
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

            RefundRequested::dispatch($refund);

            return $refund;
        });
    }

    /**
     * Approve a requested refund, for what was asked or less. A full refund
     * (everything still paid) cancels the booking and frees its units.
     *
     * The ledger may have moved since the request, so the amount is checked
     * against it as it is now.
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

            RefundApproved::dispatch($locked);

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

            RefundRejected::dispatch($locked);

            return $locked;
        });
    }

    /**
     * Pay an approved refund out, or try again one Stripe failed. Cash (or no
     * named payment, or a card payment with no Stripe reference to reverse) is
     * handed back by hand and recorded here; a Stripe payment goes back
     * through Stripe, and is processed once Stripe says it succeeded, now or
     * by webhook later.
     *
     * An approved refund Stripe is already working on (it answered `pending`)
     * is asked about rather than created again, so pressing Process twice can
     * never pay it out twice. Stripe is called with no transaction open, then
     * the outcome is written under the locks.
     *
     * @throws ValidationException for a refund that is neither approved nor failed
     * @throws ApiErrorException when Stripe refuses
     */
    public function process(Refund $refund): Refund
    {
        if ($refund->status !== RefundStatus::Failed) {
            $this->ensureStatus($refund, RefundStatus::Approved);
        }

        $payment = $refund->payment;

        if ($payment === null || ! $refund->goesThroughStripe()) {
            return $this->settle($refund, RefundStatus::Processed);
        }

        $stripeRefund = $refund->status === RefundStatus::Approved && $refund->stripe_refund_id !== null
            ? $this->gateway->retrieveRefund($refund->stripe_refund_id)
            : $this->gateway->refund($refund, $payment);

        return $this->settle($refund, match ($stripeRefund['status']) {
            'succeeded' => RefundStatus::Processed,
            'failed', 'canceled' => RefundStatus::Failed,
            default => RefundStatus::Approved,
        }, $stripeRefund['id']);
    }

    /**
     * Record an approved or failed refund as handed back another way (cash, a
     * bank transfer), for one Stripe cannot pay out, such as to a closed card.
     *
     * @throws ValidationException for a refund that is neither approved nor failed
     */
    public function processByHand(Refund $refund): Refund
    {
        if ($refund->status !== RefundStatus::Failed) {
            $this->ensureStatus($refund, RefundStatus::Approved);
        }

        return $this->settle($refund, RefundStatus::Processed);
    }

    /**
     * Write a refund's outcome: Processed counts its money as returned (and
     * marks the payment it reverses), Failed leaves the money where it was,
     * still owed, Approved means Stripe is still working on it. An approved
     * or failed refund moves; a processed one moves only back to Failed, when
     * Stripe fails it after all, and its money then counts as not returned.
     * A second delivery of the same outcome changes nothing.
     */
    public function settle(Refund $refund, RefundStatus $outcome, ?string $stripeRefundId = null): Refund
    {
        return DB::transaction(function () use ($refund, $outcome, $stripeRefundId): Refund {
            [$booking, $locked] = $this->lockBoth($refund);

            if ($locked->status === RefundStatus::Processed && $outcome === RefundStatus::Failed) {
                return $this->reverse($booking, $locked);
            }

            if (! in_array($locked->status, [RefundStatus::Approved, RefundStatus::Failed], true)) {
                return $locked;
            }

            // Still the same outcome of the same Stripe refund: a replay.
            if ($locked->status === $outcome && ($stripeRefundId === null || $stripeRefundId === $locked->stripe_refund_id)) {
                return $locked;
            }

            $locked->forceFill([
                'status' => $outcome,
                'stripe_refund_id' => $stripeRefundId ?? $locked->stripe_refund_id,
                'processed_at' => $outcome === RefundStatus::Processed ? now() : null,
            ])->save();

            if ($outcome === RefundStatus::Failed) {
                $this->recordAuditEvent(AuditAction::RefundFailed, $booking, __(':amount refund on :reference failed at Stripe; it is still owed.', [
                    'amount' => $locked->amount->format(),
                    'reference' => $booking->reference,
                ]), context: ['refund_id' => $locked->id, 'stripe_refund_id' => $locked->stripe_refund_id]);
            }

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

            RefundProcessed::dispatch($locked);

            return $locked;
        });
    }

    /**
     * Get how much of the booking can still be refunded: what was paid, less
     * what has gone back, less what other refunds already count on.
     */
    public function refundableMinor(Booking $booking, ?Refund $except = null): int
    {
        $spokenFor = $booking->refunds()->outstanding();

        if ($except !== null) {
            $spokenFor->whereKeyNot($except->id);
        }

        return max(0, $booking->amount_paid_minor - $booking->amount_refunded_minor - (int) $spokenFor->sum('amount_minor'));
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
     * has gone back, PartiallyRefunded before, and Succeeded again should a
     * reversed refund leave nothing gone back. It still counts as paid; the
     * refund is what is subtracted.
     */
    private function markPaymentRefunded(?Payment $payment): void
    {
        if ($payment === null) {
            return;
        }

        $refundedMinor = (int) $payment->refunds()->countingAsRefunded()->sum('amount_minor');

        $payment->forceFill([
            'status' => match (true) {
                $refundedMinor <= 0 => PaymentRecordStatus::Succeeded,
                $refundedMinor >= $payment->amount_minor => PaymentRecordStatus::Refunded,
                default => PaymentRecordStatus::PartiallyRefunded,
            },
        ])->save();
    }

    /**
     * Full when the amount is everything paid that is not already returned or
     * promised back by another approved (or failed, so still owed) refund, so
     * nothing paid would be left with the agency; partial otherwise. Other
     * requests are not counted: one may still be rejected.
     */
    private function typeFor(Booking $booking, int $amountMinor, ?Refund $except = null): RefundType
    {
        $committed = $booking->refunds()->whereIn('status', [RefundStatus::Approved, RefundStatus::Failed]);

        if ($except !== null) {
            $committed->whereKeyNot($except->id);
        }

        $leftMinor = max(0, $booking->amount_paid_minor - $booking->amount_refunded_minor - (int) $committed->sum('amount_minor'));

        return $amountMinor >= $leftMinor ? RefundType::Full : RefundType::Partial;
    }

    /**
     * Undo a processed refund Stripe failed after all: it is Failed, still
     * owed, and its money counts as not returned, on the booking and on the
     * payment it was to reverse.
     */
    private function reverse(Booking $booking, Refund $refund): Refund
    {
        $refund->forceFill(['status' => RefundStatus::Failed, 'processed_at' => null])->save();

        $before = ['amount_refunded_minor' => $booking->amount_refunded_minor, 'payment_status' => $booking->payment_status->value];
        $booking->recomputeMoney();
        $this->markPaymentRefunded($refund->payment);

        $this->recordAuditEvent(AuditAction::RefundFailed, $booking, __(':amount refund on :reference failed at Stripe after it was paid out; it is owed again.', [
            'amount' => $refund->amount->format(),
            'reference' => $booking->reference,
        ]), [
            'amount_refunded_minor' => ['old' => $before['amount_refunded_minor'], 'new' => $booking->amount_refunded_minor],
            'payment_status' => ['old' => $before['payment_status'], 'new' => $booking->payment_status->value],
        ], ['refund_id' => $refund->id, 'stripe_refund_id' => $refund->stripe_refund_id]);

        return $refund;
    }

    /**
     * Refuse an amount beyond what the booking can still refund, or, for a
     * named payment, beyond what is left of that payment once what has gone
     * back from it, or is on its way (asked for, approved, or failed and still
     * owed), is taken off.
     *
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

        $leftOnPayment = $payment->amount_minor - (int) $payment->refunds()
            ->whereIn('status', [RefundStatus::Requested, RefundStatus::Approved, RefundStatus::Failed, RefundStatus::Processed])
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
