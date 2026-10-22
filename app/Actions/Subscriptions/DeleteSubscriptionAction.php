<?php

namespace App\Actions\Subscriptions;

use App\Concerns\RequiresDeletionReason;
use App\Enums\AuditAction;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Audit\AuditRecorder;

class DeleteSubscriptionAction
{
    use RequiresDeletionReason;

    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Soft-delete a subscription with its invoices and their payments.
     *
     * A live subscription is ended first - cancelled, out of the agency's
     * active slot - because a soft-deleted row would otherwise keep holding
     * that unique slot and the agency could never be put on a plan again.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, Subscription $subscription, array $input): void
    {
        $reason = $this->validatedReason($input);

        $subscription->getConnection()->transaction(function () use ($actor, $subscription, $reason): void {
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            $cascaded = $this->deleteBillingRecordsOf($locked);

            if ($locked->status->isLive()) {
                $locked->update([
                    'active_for_tenant_id' => null,
                    'status' => SubscriptionStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancel_reason' => $reason,
                    'next_billing_on' => null,
                    'ends_on' => today(),
                ]);
            }

            $locked->delete();

            $this->auditRecorder->recordPlatformEvent(
                AuditAction::SubscriptionDeleted,
                $actor,
                $locked->tenant,
                $reason,
                subject: $locked,
                context: ['cascaded' => $cascaded],
            );
        });
    }

    /**
     * Soft-delete a subscription's invoices and their payments, returning the
     * ids deleted. Rows already deleted on their own are left out, so a
     * restore of this cascade brings back only what it took.
     *
     * @return array{subscription_invoice: list<int>, subscription_payment: list<int>}
     */
    public function deleteBillingRecordsOf(Subscription $subscription): array
    {
        $invoiceIds = array_values(array_map(intval(...), $subscription->invoices()->pluck('id')->all()));
        $paymentIds = array_values(array_map(intval(...), SubscriptionPayment::query()->whereIn('subscription_invoice_id', $invoiceIds)->pluck('id')->all()));

        SubscriptionPayment::query()->whereKey($paymentIds)->delete();
        SubscriptionInvoice::query()->whereKey($invoiceIds)->delete();

        return [
            'subscription_payment' => $paymentIds,
            'subscription_invoice' => $invoiceIds,
        ];
    }
}
