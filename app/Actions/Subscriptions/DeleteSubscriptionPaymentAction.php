<?php

namespace App\Actions\Subscriptions;

use App\Concerns\RequiresDeletionReason;
use App\Enums\AuditAction;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Audit\AuditRecorder;

class DeleteSubscriptionPaymentAction
{
    use RequiresDeletionReason;

    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Soft-delete a payment, such as one recorded by mistake, and recompute
     * what its invoice has been paid.
     *
     * The payment stays in the table with `deleted_at` set, but drops out of
     * the SUM, so the invoice's paid amount and status correct themselves.
     * Rows are locked subscription first, invoice second, as billing does.
     *
     * Deleting a payment can make a settled invoice overdue again; the daily
     * billing run then marks the subscription PastDue, as for any overdue
     * invoice, rather than this action doing it a second way.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, SubscriptionPayment $payment, array $input): void
    {
        $reason = $this->validatedReason($input);

        $payment->getConnection()->transaction(function () use ($actor, $payment, $reason): void {
            $invoice = SubscriptionInvoice::query()->whereKey($payment->subscription_invoice_id)->firstOrFail();
            $subscription = Subscription::query()->whereKey($invoice->subscription_id)->lockForUpdate()->firstOrFail();
            $locked = SubscriptionInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $previousAmountPaidMinor = $locked->amount_paid_minor;
            $previousStatus = $locked->status;

            SubscriptionPayment::query()->whereKey($payment->id)->firstOrFail()->delete();
            $locked->recomputeAmountPaid();

            $this->auditRecorder->recordPlatformEvent(
                AuditAction::SubscriptionPaymentDeleted,
                $actor,
                $subscription->tenant,
                $reason,
                changes: [
                    'amount_paid_minor' => ['old' => $previousAmountPaidMinor, 'new' => $locked->amount_paid_minor],
                    'status' => ['old' => $previousStatus->value, 'new' => $locked->status->value],
                ],
                subject: $payment,
            );
        });
    }
}
