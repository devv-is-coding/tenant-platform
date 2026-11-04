<?php

namespace App\Services\Payment;

use App\Concerns\RecordsAuditEvents;
use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\ValueObjects\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Writes money received against a booking, and keeps the booking's money axis
 * true to it: in the same transaction as each payment, with the booking's row
 * locked, `amount_paid_minor` is recomputed as a SUM over the ledger (never
 * incremented) and `payment_status` derived from it (never assigned).
 *
 * Two ways in. Staff record cash and bank transfers by hand, and may not take
 * more than is owed. Card payments come only from Stripe's signed webhook,
 * keyed by what Stripe settled, so a replayed event writes nothing new; money
 * Stripe already took is recorded even when it overpays, because refusing it
 * would not give it back.
 */
class PaymentService
{
    use RecordsAuditEvents;

    /**
     * Record a cash or bank-transfer payment a staff member took by hand.
     *
     * @throws ValidationException for a method, amount or date the booking cannot take
     */
    public function recordByStaff(Booking $booking, Money $amount, PaymentMethod $method, CarbonInterface $paidAt, User $recordedBy): Payment
    {
        if (! $method->isRecordedByStaff()) {
            throw ValidationException::withMessages([
                'method' => __(':method payments are confirmed by the payment provider, never recorded by hand.', ['method' => $method->label()]),
            ]);
        }

        if ($paidAt->isFuture()) {
            throw ValidationException::withMessages(['paid_at' => __('The payment date cannot be in the future.')]);
        }

        $this->ensureIsPositiveIn($amount, $booking);

        return DB::transaction(function () use ($booking, $amount, $method, $paidAt, $recordedBy): Payment {
            $locked = $this->lock($booking);

            if (! $locked->approval_status->isActive()) {
                throw ValidationException::withMessages([
                    'amount' => __(':reference is :status and takes no payments.', [
                        'reference' => $locked->reference,
                        'status' => mb_strtolower($locked->approval_status->label()),
                    ]),
                ]);
            }

            if ($amount->minor > $locked->balance()->minor) {
                throw ValidationException::withMessages([
                    'amount' => __('The amount is more than the :balance still owed.', ['balance' => $locked->balance()->format()]),
                ]);
            }

            return $this->write($locked, AuditAction::PaymentRecorded, [
                'amount' => $amount,
                'method' => $method,
                'status' => PaymentRecordStatus::Succeeded,
                'paid_at' => $paidAt,
                'recorded_by' => $recordedBy->id,
            ]);
        });
    }

    /**
     * Record a payment Stripe settled, from its signed webhook.
     *
     * The idempotency key names what Stripe settled (such as its invoice), so
     * the same event delivered twice, or two events for one invoice, return
     * the payment already written instead of crediting the booking again.
     *
     * @throws InvalidArgumentException for an amount in another currency than the booking's
     */
    public function recordFromGateway(
        Booking $booking,
        Money $amount,
        string $idempotencyKey,
        CarbonInterface $paidAt,
        ?string $paymentIntentId = null,
        ?string $chargeId = null,
    ): Payment {
        if ($amount->currency !== $booking->currency || $amount->minor <= 0) {
            throw new InvalidArgumentException("A payment of {$amount->toDecimal()} {$amount->currency} cannot be recorded against {$booking->reference}, booked in {$booking->currency}.");
        }

        try {
            return DB::transaction(function () use ($booking, $amount, $idempotencyKey, $paidAt, $paymentIntentId, $chargeId): Payment {
                $locked = $this->lock($booking);

                $existing = Payment::withTrashed()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }

                return $this->write($locked, AuditAction::PaymentReceived, [
                    'amount' => $amount,
                    'method' => PaymentMethod::Stripe,
                    'status' => PaymentRecordStatus::Succeeded,
                    'paid_at' => $paidAt,
                    'idempotency_key' => $idempotencyKey,
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'stripe_charge_id' => $chargeId,
                ], [
                    'overpaid_minor' => max(0, $locked->amount_paid_minor + $amount->minor - $locked->total_minor),
                    'booking_active' => $locked->approval_status->isActive(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Another delivery of the same event wrote it between the check and the insert.
            return Payment::withTrashed()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }
    }

    /**
     * Write the payment against the locked booking, recompute the booking's
     * money from the ledger, and record it in the agency's log.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $context
     */
    private function write(Booking $locked, AuditAction $action, array $attributes, ?array $context = null): Payment
    {
        $before = ['amount_paid_minor' => $locked->amount_paid_minor, 'payment_status' => $locked->payment_status->value];

        // Counted with deleted payments too: they keep their references.
        $payment = $locked->payments()->create([
            'reference' => $locked->reference.'-P'.($locked->payments()->withTrashed()->count() + 1),
            ...$attributes,
        ]);

        $locked->recomputeMoney();

        $this->recordAuditEvent($action, $locked, __(':amount :method received for :reference.', [
            'amount' => $payment->amount->format(),
            'method' => mb_strtolower($payment->method->label()),
            'reference' => $locked->reference,
        ]), [
            'amount_paid_minor' => ['old' => $before['amount_paid_minor'], 'new' => $locked->amount_paid_minor],
            'payment_status' => ['old' => $before['payment_status'], 'new' => $locked->payment_status->value],
        ], ['payment_reference' => $payment->reference, ...($context ?? [])]);

        return $payment;
    }

    /**
     * Lock the booking's row for the rest of the transaction, so two payments
     * against it take turns and each sums a ledger the other has finished.
     */
    private function lock(Booking $booking): Booking
    {
        return Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
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
}
