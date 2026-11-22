<?php

namespace App\Actions\Subscriptions;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Billing\PlatformCheckout;
use App\ValueObjects\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class RecordPlatformCheckoutAction
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private StartSubscriptionAction $startSubscription,
        private ClearPastDueAction $clearPastDue,
    ) {}

    /**
     * Write what a completed platform Checkout session paid for (TA-27).
     *
     * A plan bought: the agency is put on it through StartSubscriptionAction,
     * starting today, and the new period's invoice is issued at the price the
     * Admin was quoted, the credit for the old plan's unused days shown on it,
     * then settled by the card payment. An invoice paid: the payment is
     * recorded against it, and settling the last overdue invoice moves a
     * PastDue subscription back to Active.
     *
     * **Once per session.** Stripe delivers an event at least once; a replayed
     * one finds its payment, by the unique idempotency key, and changes
     * nothing. **Only a paid session counts**: a declined card, or an async
     * method still pending, changes nothing, so the plan stays as it was.
     *
     * @param  array<string, mixed>  $session  the Checkout session object of the event
     * @return bool whether anything was recorded
     */
    public function handle(array $session): bool
    {
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $sessionId = $session['id'] ?? null;
        $amountTotal = $session['amount_total'] ?? null;
        $currency = $session['currency'] ?? null;

        if (($session['payment_status'] ?? null) !== 'paid' || ! is_string($sessionId) || ! is_int($amountTotal) || $amountTotal <= 0 || ! is_string($currency)) {
            return false;
        }

        $tenant = is_string($metadata['tenant_id'] ?? null) ? Tenant::query()->find($metadata['tenant_id']) : null;
        $key = 'stripe:checkout:'.$sessionId;

        if ($tenant === null || SubscriptionPayment::withTrashed()->where('idempotency_key', $key)->exists()) {
            return false;
        }

        $admin = is_numeric($metadata['admin_id'] ?? null) ? User::query()->find((int) $metadata['admin_id']) : null;
        $paid = Money::ofMinor($amountTotal, strtoupper($currency));
        $paymentIntentId = is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null;

        try {
            return match ($metadata['purpose'] ?? null) {
                PlatformCheckout::PURPOSE_PLAN => $this->recordPlan($tenant, $admin, $metadata, $paid, $key, $paymentIntentId),
                PlatformCheckout::PURPOSE_INVOICE => $this->recordInvoice($tenant, $metadata, $paid, $key, $paymentIntentId),
                default => false,
            };
        } catch (UniqueConstraintViolationException) {
            // The same session delivered twice at once: the other delivery wrote it.
            return false;
        }
    }

    /**
     * Put the agency on the plan it paid for, with the period's invoice
     * settled.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws ValidationException for a plan withdrawn since it was paid for
     */
    private function recordPlan(Tenant $tenant, ?User $admin, array $metadata, Money $paid, string $key, ?string $paymentIntentId): bool
    {
        // Withdrawn ones too: StartSubscriptionAction refuses those, loudly,
        // rather than the payment vanishing as unplaceable.
        $plan = is_numeric($metadata['subscription_plan_id'] ?? null) ? SubscriptionPlan::withTrashed()->find((int) $metadata['subscription_plan_id']) : null;

        if ($plan === null) {
            return false;
        }

        $creditMinor = is_numeric($metadata['credit_minor'] ?? null) ? (int) $metadata['credit_minor'] : 0;

        return $tenant->getConnection()->transaction(function () use ($tenant, $admin, $plan, $metadata, $creditMinor, $paid, $key, $paymentIntentId): bool {
            $subscription = $this->startSubscription->handle($admin, $tenant, ['subscription_plan_id' => $plan->id]);

            $invoice = $subscription->invoices()->create([
                'tenant_id' => $tenant->id,
                'reference' => SubscriptionInvoice::nextReference($subscription->current_period_end_on),
                'period_start_on' => $subscription->current_period_start_on,
                'period_end_on' => $subscription->current_period_end_on,
                'issued_on' => today(),
                'due_on' => today(),
                // What the Admin was quoted and paid, even if the plan was repriced since.
                'amount_minor' => is_numeric($metadata['amount_minor'] ?? null)
                    ? (int) $metadata['amount_minor']
                    : max(0, $subscription->amount_minor - $creditMinor),
                'credit_minor' => $creditMinor,
                'currency' => $subscription->currency,
            ]);

            $this->auditRecorder->recordPlatformEvent(
                AuditAction::SubscriptionInvoiceIssued,
                null,
                $tenant,
                subject: $invoice,
                context: ['credit_minor' => $creditMinor],
            );

            $this->recordPayment($tenant, $invoice, $paid, $key, $paymentIntentId);

            return true;
        });
    }

    /**
     * Record the card payment against the invoice it paid.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function recordInvoice(Tenant $tenant, array $metadata, Money $paid, string $key, ?string $paymentIntentId): bool
    {
        $invoiceId = is_numeric($metadata['subscription_invoice_id'] ?? null) ? (int) $metadata['subscription_invoice_id'] : null;
        $invoice = $invoiceId === null ? null : SubscriptionInvoice::query()->whereKey($invoiceId)->where('tenant_id', $tenant->id)->first();

        if ($invoice === null) {
            return false;
        }

        return $invoice->getConnection()->transaction(function () use ($tenant, $invoice, $paid, $key, $paymentIntentId): bool {
            // Subscription first, then invoice, as billing locks them.
            $subscription = Subscription::query()->whereKey($invoice->subscription_id)->lockForUpdate()->firstOrFail();
            $locked = SubscriptionInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $this->recordPayment($tenant, $locked, $paid, $key, $paymentIntentId);
            $this->clearPastDue->handle(null, $subscription);

            return true;
        });
    }

    /**
     * Write the payment, then recompute the invoice from its payments. The
     * money is recorded as it came in, even should it now pay more than is
     * owed: Stripe has taken it.
     */
    private function recordPayment(Tenant $tenant, SubscriptionInvoice $invoice, Money $paid, string $key, ?string $paymentIntentId): void
    {
        $payment = $invoice->payments()->create([
            'tenant_id' => $tenant->id,
            'reference' => SubscriptionPayment::nextReferenceFor($invoice),
            'amount_minor' => $paid->minor,
            'currency' => $paid->currency,
            'method' => PaymentMethod::Stripe,
            'status' => PaymentRecordStatus::Succeeded,
            'paid_at' => now(),
            'idempotency_key' => $key,
            'stripe_payment_intent_id' => $paymentIntentId,
        ]);

        $before = ['amount_paid_minor' => $invoice->amount_paid_minor, 'status' => $invoice->status->value];
        $invoice->recomputeAmountPaid();

        $this->auditRecorder->recordPlatformEvent(
            AuditAction::SubscriptionPaymentRecorded,
            null,
            $tenant,
            changes: [
                'amount_paid_minor' => ['old' => $before['amount_paid_minor'], 'new' => $invoice->amount_paid_minor],
                'status' => ['old' => $before['status'], 'new' => $invoice->status->value],
            ],
            subject: $payment,
            context: ['stripe_payment_intent_id' => $paymentIntentId],
        );
    }
}
