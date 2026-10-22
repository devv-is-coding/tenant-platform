<?php

namespace App\Actions\Subscriptions;

use App\Enums\AuditAction;
use App\Enums\BillingInterval;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SaveSubscriptionPlanAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Add a plan to the price list, or rename or reprice the given one.
     *
     * Repricing a plan only changes what agencies put on it from now on: those
     * already on it keep the terms copied onto their subscription.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input, ?SubscriptionPlan $plan = null): SubscriptionPlan
    {
        $validated = Validator::make($input, $this->rules($plan), $this->messages())->validate();

        $amount = Money::parse($validated['amount'], $plan->currency ?? config('tenancy.billing_currency'));
        $attributes = [
            'name' => trim($validated['name']),
            'amount_minor' => $amount->minor,
            'interval' => BillingInterval::from((int) $validated['interval']),
        ];

        if ($plan === null) {
            $plan = SubscriptionPlan::create([...$attributes, 'currency' => $amount->currency, 'created_by' => $actor->id]);

            $this->auditRecorder->recordPlatformEvent(AuditAction::PlanCreated, $actor, subject: $plan);

            return $plan;
        }

        $plan->fill($attributes);
        $changes = $this->changesOf($plan);
        $plan->save();

        if ($changes !== []) {
            $this->auditRecorder->recordPlatformEvent(AuditAction::PlanUpdated, $actor, changes: $changes, subject: $plan);
        }

        return $plan;
    }

    /**
     * Get the validation rules. Amounts are typed in major units, with at
     * most two decimal places and no thousands separators.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(?SubscriptionPlan $plan = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique(SubscriptionPlan::class, 'name')->ignore($plan)->withoutTrashed()],
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
            'name.unique' => __('Another plan already has this name.'),
            'amount.regex' => __('Enter the amount as a number with at most two decimal places, without commas.'),
            'amount.not_regex' => __('The amount must be more than zero.'),
        ];
    }

    /**
     * Describe the unsaved changes to the plan, old and new.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changesOf(SubscriptionPlan $plan): array
    {
        $changes = [];

        foreach (array_keys($plan->getDirty()) as $attribute) {
            $changes[$attribute] = [
                'old' => $plan->getRawOriginal($attribute),
                'new' => $plan->getAttributes()[$attribute],
            ];
        }

        return $changes;
    }
}
