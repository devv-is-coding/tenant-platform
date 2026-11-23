<?php

namespace App\Actions\Subscriptions;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Services\Audit\AuditRecorder;
use App\ValueObjects\Money;

class RecordStripeSubscriptionPaymentAction
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private ClearPastDueAction $clearPastDue,
    ) {}

    /**
     * Record money Stripe took for one of an agency's invoices: a Checkout
     * payment (TA-27) or an automatic charge of the saved card (TA-33).
     *
     * **Once.** Keyed by what Stripe settled (the session, or the
     * PaymentIntent), and checked under the locks, so the same confirmation
     * twice (the charge's answer, then its webhook) writes one payment. The
     * money is recorded as it came in, even should it now pay more than is
     * owed: Stripe has taken it. The invoice is recomputed from its payments,
     * and settling the last overdue one moves a PastDue subscription back to
     * Active.
     *
     * Subscription first, then invoice, as billing locks them.
     *
     * @return bool whether it was recorded now, rather than already
     */
    public function handle(SubscriptionInvoice $invoice, Money $paid, string $idempotencyKey, ?string $paymentIntentId): bool
    {
        return $invoice->getConnection()->transaction(function () use ($invoice, $paid, $idempotencyKey, $paymentIntentId): bool {
            $subscription = Subscription::query()->whereKey($invoice->subscription_id)->lockForUpdate()->firstOrFail();
            $locked = SubscriptionInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (SubscriptionPayment::withTrashed()->where('idempotency_key', $idempotencyKey)->exists()) {
                return false;
            }

            $payment = $locked->payments()->create([
                'tenant_id' => $locked->tenant_id,
                'reference' => SubscriptionPayment::nextReferenceFor($locked),
                'amount_minor' => $paid->minor,
                'currency' => $paid->currency,
                'method' => PaymentMethod::Stripe,
                'status' => PaymentRecordStatus::Succeeded,
                'paid_at' => now(),
                'idempotency_key' => $idempotencyKey,
                'stripe_payment_intent_id' => $paymentIntentId,
            ]);

            $before = ['amount_paid_minor' => $locked->amount_paid_minor, 'status' => $locked->status->value];
            $locked->recomputeAmountPaid();

            $this->auditRecorder->recordPlatformEvent(
                AuditAction::SubscriptionPaymentRecorded,
                null,
                $subscription->tenant,
                changes: [
                    'amount_paid_minor' => ['old' => $before['amount_paid_minor'], 'new' => $locked->amount_paid_minor],
                    'status' => ['old' => $before['status'], 'new' => $locked->status->value],
                ],
                subject: $payment,
                context: ['stripe_payment_intent_id' => $paymentIntentId],
            );

            $this->clearPastDue->handle(null, $subscription);

            return true;
        });
    }
}
