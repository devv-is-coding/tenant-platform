<?php

namespace App\Actions\Subscriptions;

use App\Enums\AuditAction;
use App\Enums\PlanChangeRequestStatus;
use App\Models\PlanChangeRequest;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RequestPlanChangeAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * File an agency admin's request to be put on a plan from the price list.
     * A super admin approves or declines it.
     *
     * Authorized here rather than only by the page that calls it, like a
     * restore request: the agency's own admin, for the agency. Never gated by
     * the agency's plan or status, since asking is how an agency without a
     * plan gets one.
     *
     * One request waits per agency: the pending slot's unique key holds that
     * even when two are sent at once.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, Tenant $tenant, array $input): PlanChangeRequest
    {
        Gate::forUser($actor)->authorize('create', [PlanChangeRequest::class, $tenant]);

        $validated = Validator::make($input, $this->rules(), $this->messages())->validate();
        $plan = SubscriptionPlan::query()->whereKey($validated['subscription_plan_id'])->firstOrFail();

        if ((int) $tenant->liveSubscription()->value('subscription_plan_id') === $plan->id) {
            throw ValidationException::withMessages(['subscription_plan_id' => __('The agency is already on :plan.', ['plan' => $plan->name])]);
        }

        if (PlanChangeRequest::query()->where('pending_for_tenant_id', $tenant->id)->exists()) {
            $this->refuseSecondRequest();
        }

        $reason = trim((string) ($validated['reason'] ?? ''));

        try {
            $request = PlanChangeRequest::create([
                'tenant_id' => $tenant->id,
                'pending_for_tenant_id' => $tenant->id,
                'subscription_plan_id' => $plan->id,
                'requested_by' => $actor->id,
                'reason' => $reason === '' ? null : $reason,
                'status' => PlanChangeRequestStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->refuseSecondRequest();
        }

        $this->auditRecorder->recordPlatformEvent(
            AuditAction::PlanChangeRequested,
            $actor,
            $tenant,
            $request->reason,
            subject: $request,
            context: ['plan' => $plan->name, 'tier' => $plan->tier->value],
        );

        return $request;
    }

    /**
     * Withdraw the agency's pending request, freeing its slot for another.
     */
    public function withdraw(User $actor, PlanChangeRequest $request): void
    {
        Gate::forUser($actor)->authorize('withdraw', $request);

        $request->getConnection()->transaction(function () use ($actor, $request): void {
            $locked = PlanChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PlanChangeRequestStatus::Pending) {
                throw ValidationException::withMessages(['withdraw' => __('This request has already been decided.')]);
            }

            $locked->update([
                'pending_for_tenant_id' => null,
                'status' => PlanChangeRequestStatus::Withdrawn,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);

            $this->auditRecorder->recordPlatformEvent(AuditAction::PlanChangeWithdrawn, $actor, $locked->tenant, subject: $locked);
        });

        $request->refresh();
    }

    /**
     * Get the rules for a plan request. Only a plan still in the price list
     * can be asked for. Public so the agency's page can share them.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'subscription_plan_id' => ['required', 'integer', Rule::exists(SubscriptionPlan::class, 'id')->withoutTrashed()],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'subscription_plan_id.required' => __('Choose a plan.'),
            'subscription_plan_id.exists' => __('That plan is no longer offered. Choose another.'),
        ];
    }

    /**
     * Refuse a request while another one waits.
     *
     * @throws ValidationException
     */
    private function refuseSecondRequest(): never
    {
        throw ValidationException::withMessages([
            'subscription_plan_id' => __('The agency already has a plan request waiting. Withdraw it to ask for another plan.'),
        ]);
    }
}
