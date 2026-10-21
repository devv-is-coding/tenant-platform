<?php

namespace App\Actions\Subscriptions;

use App\Enums\AuditAction;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditRecorder;

class ClearPastDueAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Move a PastDue subscription back to Active once none of its invoices is
     * overdue any more - after the last overdue invoice is paid, or deleted.
     *
     * The agency's own status is left alone: reactivating a suspended agency
     * stays a super admin's decision, never a side effect of the money. Call it
     * inside the transaction that changed the invoices, with the subscription
     * row locked.
     */
    public function handle(User $actor, Subscription $subscription): bool
    {
        if ($subscription->status !== SubscriptionStatus::PastDue
            || $subscription->invoices()->overdue(today())->exists()) {
            return false;
        }

        $subscription->update(['status' => SubscriptionStatus::Active]);

        $this->auditRecorder->recordPlatformEvent(
            AuditAction::SubscriptionPastDueCleared,
            $actor,
            $subscription->tenant,
            changes: ['status' => ['old' => SubscriptionStatus::PastDue->value, 'new' => SubscriptionStatus::Active->value]],
            subject: $subscription,
        );

        return true;
    }
}
