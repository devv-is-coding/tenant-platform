<?php

namespace App\Actions\Subscriptions;

use App\Concerns\RequiresDeletionReason;
use App\Enums\AuditAction;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Audit\AuditRecorder;

class DeleteSubscriptionPlanAction
{
    use RequiresDeletionReason;

    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Soft-delete a plan, withdrawing it from the price list.
     *
     * Agencies already on the plan are untouched: their subscription holds its
     * own copy of the terms and keeps billing until it is changed or ended.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, SubscriptionPlan $plan, array $input): void
    {
        $reason = $this->validatedReason($input);

        $plan->delete();

        $this->auditRecorder->recordPlatformEvent(AuditAction::PlanDeleted, $actor, description: $reason, subject: $plan);
    }
}
