<?php

namespace App\Actions\Subscriptions;

use App\Enums\AuditAction;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Billing\PlatformCheckout;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class SchedulePlanChangeAction
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private StartSubscriptionAction $startSubscription,
    ) {}

    /**
     * Have the agency's Admin's choice of a plan that is not a move up (a
     * lower tier, or the same tier billed differently) wait for the paid
     * period to end (TA-27): the agency keeps what it paid for until then. A
     * move up is bought and starts at once instead (PlatformCheckout).
     *
     * One change waits at a time: choosing again replaces it.
     *
     * @throws ValidationException for an agency with no plan, a withdrawn or current plan, or a move up
     */
    public function schedule(User $admin, Tenant $tenant, SubscriptionPlan $plan): Subscription
    {
        return $tenant->getConnection()->transaction(function () use ($admin, $tenant, $plan): Subscription {
            $live = $this->lockLive($tenant);

            if ($live === null) {
                throw ValidationException::withMessages(['plan' => __('The agency has no plan to move from. Buy one instead.')]);
            }

            if ($plan->trashed()) {
                throw ValidationException::withMessages(['plan' => __('That plan is no longer offered. Choose another.')]);
            }

            if ($plan->id === $live->subscription_plan_id) {
                throw ValidationException::withMessages(['plan' => __('The agency is already on :plan.', ['plan' => $plan->name])]);
            }

            if (PlatformCheckout::isUpgrade($live, $plan)) {
                throw ValidationException::withMessages(['plan' => __(':plan is a move up: pay for it, and it starts at once.', ['plan' => $plan->name])]);
            }

            if ($live->next_billing_on === null) {
                throw ValidationException::withMessages(['plan' => __('The agency\'s plan does not renew, so there is no next period to move in. Ask the platform.')]);
            }

            $live->update(['scheduled_plan_id' => $plan->id, 'scheduled_by' => $admin->id]);

            $this->auditRecorder->recordPlatformEvent(
                AuditAction::SubscriptionChangeScheduled,
                $admin,
                $tenant,
                __('Moves to :plan on :date.', ['plan' => $plan->name, 'date' => $live->next_billing_on->toFormattedDateString()]),
                subject: $live,
                context: ['subscription_plan_id' => $plan->id],
            );

            return $live;
        });
    }

    /**
     * Keep the agency on its plan: drop the change waiting for the period's end.
     *
     * @throws ValidationException when no change is waiting
     */
    public function unschedule(User $admin, Tenant $tenant): void
    {
        $tenant->getConnection()->transaction(function () use ($admin, $tenant): void {
            $live = $this->lockLive($tenant);

            if ($live?->scheduled_plan_id === null) {
                throw ValidationException::withMessages(['plan' => __('No plan change is waiting.')]);
            }

            $plan = $live->scheduledPlan()->first();
            $live->update(['scheduled_plan_id' => null, 'scheduled_by' => null]);

            $this->auditRecorder->recordPlatformEvent(
                AuditAction::SubscriptionChangeUnscheduled,
                $admin,
                $tenant,
                __('Stays on :plan; the move to :scheduled is off.', ['plan' => $live->plan_name, 'scheduled' => $plan->name ?? '—']),
                subject: $live,
            );
        });
    }

    /**
     * Make the waiting change once the paid period has ended, before the
     * billing run bills the next one, so it is billed at the new plan's
     * price. A plan withdrawn meanwhile cannot be moved to: the change is
     * dropped and the agency stays where it is.
     *
     * @return Subscription|null the agency's new subscription, or null when no change was due
     */
    public function applyIfDue(Subscription $subscription, CarbonInterface $today): ?Subscription
    {
        if ($subscription->scheduled_plan_id === null
            || $subscription->next_billing_on === null
            || $subscription->next_billing_on->gt($today)) {
            return null;
        }

        $plan = SubscriptionPlan::query()->find($subscription->scheduled_plan_id);
        $tenant = $subscription->tenant()->first();

        if ($plan !== null && $tenant !== null) {
            try {
                return $this->startSubscription->handle(User::query()->find($subscription->scheduled_by), $tenant, ['subscription_plan_id' => $plan->id]);
            } catch (ValidationException) {
                // Withdrawn between the check and the switch: dropped below.
            }
        }

        $subscription->update(['scheduled_plan_id' => null, 'scheduled_by' => null]);

        return null;
    }

    /**
     * Lock the agency's live subscription, if it has one.
     */
    private function lockLive(Tenant $tenant): ?Subscription
    {
        return Subscription::query()->where('active_for_tenant_id', $tenant->id)->lockForUpdate()->first();
    }
}
