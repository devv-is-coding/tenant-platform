<?php

namespace App\Actions\Subscriptions;

use App\Enums\AuditAction;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\ValueObjects\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StartSubscriptionAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Put an agency on a plan, starting today, and end the plan it was on.
     *
     * The plan's name, price and interval are snapshotted onto the row: there
     * is no plans table, and the row records what the agency agreed to.
     *
     * An agency has at most one live subscription, which the unique
     * `active_for_tenant_id` slot enforces. The live one is ended first, with
     * the tenant row locked, so two super admins acting at once take turns
     * rather than collide. Should a write still slip past the lock, the unique
     * key refuses it and the agency's plan is left exactly as it was.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, Tenant $tenant, array $input): Subscription
    {
        $validated = Validator::make($input, $this->rules(), $this->messages())->validate();

        $planName = trim($validated['plan_name']);
        $amount = Money::parse($validated['amount'], config('tenancy.billing_currency'));
        $interval = BillingInterval::from((int) $validated['interval']);

        return $tenant->getConnection()->transaction(function () use ($actor, $tenant, $planName, $amount, $interval): Subscription {
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $today = today();

            $previous = Subscription::query()->where('active_for_tenant_id', $locked->id)->first();

            $previous?->update([
                'active_for_tenant_id' => null,
                'status' => SubscriptionStatus::Cancelled,
                'cancelled_on' => now(),
                'cancel_reason' => __('Replaced by :plan.', ['plan' => $planName]),
                'next_billing_on' => null,
                'ends_on' => $today,
            ]);

            try {
                $subscription = Subscription::create([
                    'tenant_id' => $locked->id,
                    'active_for_tenant_id' => $locked->id,
                    'plan_name' => $planName,
                    'amount_minor' => $amount->minor,
                    'currency' => $amount->currency,
                    'interval' => $interval,
                    'status' => SubscriptionStatus::Active,
                    'starts_on' => $today,
                    'current_period_start_on' => $today,
                    'current_period_end_on' => $interval->advance($today)->subDay(),
                    'next_billing_on' => $interval->advance($today),
                    'created_by' => $actor->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'plan_name' => __('This agency was put on another plan at the same moment. Reload the page to see it.'),
                ]);
            }

            $this->auditRecorder->recordPlatformEvent(
                $previous === null ? AuditAction::SubscriptionCreated : AuditAction::SubscriptionPlanChanged,
                $actor,
                $locked,
                changes: $previous === null ? null : $this->changesBetween($previous, $subscription),
                subject: $subscription,
            );

            return $subscription;
        });
    }

    /**
     * Get the validation rules. Amounts are typed in major units, with at
     * most two decimal places and no thousands separators.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'plan_name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/', 'not_regex:/^0*(\.0*)?$/'],
            'interval' => ['required', Rule::enum(BillingInterval::class)],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => __('Enter the amount as a number with at most two decimal places, without commas.'),
            'amount.not_regex' => __('The amount must be more than zero.'),
        ];
    }

    /**
     * Describe how the new plan differs from the one it replaced.
     *
     * @return array{plan_name: array{old: string, new: string}, amount_minor: array{old: int, new: int}, interval: array{old: int, new: int}}
     */
    private function changesBetween(Subscription $previous, Subscription $subscription): array
    {
        return [
            'plan_name' => ['old' => $previous->plan_name, 'new' => $subscription->plan_name],
            'amount_minor' => ['old' => $previous->amount_minor, 'new' => $subscription->amount_minor],
            'interval' => ['old' => $previous->interval->value, 'new' => $subscription->interval->value],
        ];
    }
}
