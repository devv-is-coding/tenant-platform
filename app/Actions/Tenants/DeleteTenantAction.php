<?php

namespace App\Actions\Tenants;

use App\Actions\Subscriptions\DeleteSubscriptionAction;
use App\Concerns\RequiresDeletionReason;
use App\Enums\AuditAction;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;

class DeleteTenantAction
{
    use RequiresDeletionReason;

    public function __construct(
        private AuditRecorder $auditRecorder,
        private DeleteSubscriptionAction $deleteSubscription,
    ) {}

    /**
     * Soft-delete an agency with everything the platform holds about it: its
     * users, its domains, and its subscriptions with their invoices and
     * payments.
     *
     * Nothing is destroyed. The agency's own database is kept - only a force
     * delete drops it (see Tenant::$dispatchesEvents) - and so is the platform
     * audit trail about it. With its domains soft-deleted the agency's address
     * stops resolving, so its staff and its app lose access.
     *
     * Recorded twice, like a suspension: centrally, and as a notice in the
     * agency's own audit log. The notice is written last and inside the
     * transaction, so a failure there rolls the whole delete back.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, Tenant $tenant, array $input): void
    {
        $reason = $this->validatedReason($input);

        $tenant->getConnection()->transaction(function () use ($actor, $tenant, $reason): void {
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            // The ids each cascade took, so a restore brings back exactly these.
            $cascaded = ['subscription' => [], 'subscription_invoice' => [], 'subscription_payment' => []];

            Subscription::query()->where('tenant_id', $locked->id)->each(function (Subscription $subscription) use (&$cascaded): void {
                $billing = $this->deleteSubscription->deleteBillingRecordsOf($subscription);
                $cascaded['subscription_invoice'] = [...$cascaded['subscription_invoice'], ...$billing['subscription_invoice']];
                $cascaded['subscription_payment'] = [...$cascaded['subscription_payment'], ...$billing['subscription_payment']];
            });

            // Out of the active slot first, as for a single subscription, so
            // the slot is not held by a deleted row.
            Subscription::query()->where('active_for_tenant_id', $locked->id)->update([
                'active_for_tenant_id' => null,
                'status' => SubscriptionStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'next_billing_on' => null,
                'ends_on' => today(),
            ]);
            $cascaded['subscription'] = Subscription::query()->where('tenant_id', $locked->id)->pluck('id')->all();
            Subscription::query()->whereKey($cascaded['subscription'])->delete();

            $cascaded['user'] = $locked->users()->pluck('id')->all();
            $locked->users()->whereKey($cascaded['user'])->delete();

            $cascaded['domain'] = $locked->domains()->pluck('id')->all();
            $locked->domains()->whereKey($cascaded['domain'])->delete();

            $locked->delete();

            $this->auditRecorder->recordPlatformEvent(
                AuditAction::TenantDeleted,
                $actor,
                $locked,
                $reason,
                context: ['cascaded' => $cascaded],
            );

            $this->auditRecorder->recordTenantLifecycleNotice(AuditAction::TenantDeleted, $actor, $locked, $reason);
        });
    }
}
