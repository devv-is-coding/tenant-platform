<?php

namespace App\Actions\Subscriptions;

use App\Enums\AuditAction;
use App\Enums\PlanChangeRequestStatus;
use App\Models\PlanChangeRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DecidePlanChangeRequestAction
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private StartSubscriptionAction $startSubscription,
    ) {}

    /**
     * Approve an agency admin's plan request: the agency is put on the plan
     * through StartSubscriptionAction, so the switch, the snapshot of the
     * plan's terms and the subscription's own audit event stay in one place,
     * and the request is closed as Approved in the same transaction.
     *
     * A plan withdrawn from the price list after the request was sent cannot
     * be approved: the super admin declines it, or agrees another plan with
     * the agency's admin.
     */
    public function approve(User $actor, PlanChangeRequest $request): Subscription
    {
        Gate::forUser($actor)->authorize('decide', $request);

        return $request->getConnection()->transaction(function () use ($actor, $request): Subscription {
            $locked = $this->lockPending($request, 'decision');
            $tenant = $locked->tenant;

            if ($tenant === null || $tenant->trashed()) {
                throw ValidationException::withMessages(['decision' => __('The agency is deleted. Decline the request instead.')]);
            }

            if ($locked->plan === null || $locked->plan->trashed()) {
                throw ValidationException::withMessages(['decision' => __('This plan was withdrawn from the price list after the request was sent. Decline the request, or agree another plan with the agency\'s admin.')]);
            }

            $subscription = $this->startSubscription->handle($actor, $tenant, ['subscription_plan_id' => $locked->plan->id]);

            $locked->update([
                'pending_for_tenant_id' => null,
                'status' => PlanChangeRequestStatus::Approved,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);

            $this->auditRecorder->recordPlatformEvent(
                AuditAction::PlanChangeApproved,
                $actor,
                $tenant,
                subject: $locked,
                context: ['subscription_id' => $subscription->id],
            );

            return $subscription;
        });
    }

    /**
     * Decline an agency admin's plan request, with a reason the admin can be
     * told. The agency's plan is left as it was.
     *
     * @param  array<string, mixed>  $input
     */
    public function decline(User $actor, PlanChangeRequest $request, array $input): void
    {
        Gate::forUser($actor)->authorize('decide', $request);

        $validated = Validator::make($input, ['decision_reason' => ['required', 'string', 'max:1000']], [
            'decision_reason.required' => __('Say why the request is declined.'),
        ])->validate();

        $request->getConnection()->transaction(function () use ($actor, $request, $validated): void {
            $locked = $this->lockPending($request, 'decision_reason');

            $locked->update([
                'pending_for_tenant_id' => null,
                'status' => PlanChangeRequestStatus::Declined,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_reason' => trim($validated['decision_reason']),
            ]);

            $this->auditRecorder->recordPlatformEvent(
                AuditAction::PlanChangeDeclined,
                $actor,
                $locked->tenant,
                $locked->decision_reason,
                subject: $locked,
            );
        });

        $request->refresh();
    }

    /**
     * Lock the request and refuse one that was already decided or withdrawn,
     * so two super admins acting at once cannot both decide it.
     *
     * @throws ValidationException
     */
    private function lockPending(PlanChangeRequest $request, string $errorKey): PlanChangeRequest
    {
        $locked = PlanChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== PlanChangeRequestStatus::Pending) {
            throw ValidationException::withMessages([$errorKey => __('This request has already been decided.')]);
        }

        return $locked;
    }
}
