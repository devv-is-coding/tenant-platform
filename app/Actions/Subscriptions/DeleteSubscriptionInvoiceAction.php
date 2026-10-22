<?php

namespace App\Actions\Subscriptions;

use App\Concerns\RequiresDeletionReason;
use App\Enums\AuditAction;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Audit\AuditRecorder;

class DeleteSubscriptionInvoiceAction
{
    use RequiresDeletionReason;

    public function __construct(
        private AuditRecorder $auditRecorder,
        private ClearPastDueAction $clearPastDue,
    ) {}

    /**
     * Soft-delete an invoice together with its payments.
     *
     * The billing run will not issue that period again: its "already billed?"
     * check counts deleted invoices too. If this was the last overdue invoice,
     * a PastDue subscription goes back to Active.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, SubscriptionInvoice $invoice, array $input): void
    {
        $reason = $this->validatedReason($input);

        $invoice->getConnection()->transaction(function () use ($actor, $invoice, $reason): void {
            $subscription = Subscription::query()->whereKey($invoice->subscription_id)->lockForUpdate()->firstOrFail();
            $locked = SubscriptionInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $paymentIds = $locked->payments()->pluck('id')->all();
            $locked->payments()->whereKey($paymentIds)->delete();
            $locked->delete();

            $this->auditRecorder->recordPlatformEvent(
                AuditAction::SubscriptionInvoiceDeleted,
                $actor,
                $subscription->tenant,
                $reason,
                subject: $locked,
                context: ['cascaded' => ['subscription_payment' => $paymentIds]],
            );

            $this->clearPastDue->handle($actor, $subscription);
        });
    }
}
