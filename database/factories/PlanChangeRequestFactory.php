<?php

namespace Database\Factories;

use App\Enums\PlanChangeRequestStatus;
use App\Enums\Role;
use App\Models\PlanChangeRequest;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanChangeRequest>
 */
class PlanChangeRequestFactory extends Factory
{
    /**
     * A pending request, from the agency's admin, for a plan in the price list.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'pending_for_tenant_id' => fn (array $attributes): mixed => $attributes['tenant_id'],
            'subscription_plan_id' => SubscriptionPlan::factory(),
            'requested_by' => fn (array $attributes): int => User::factory()->role(Role::Admin)->create([
                'tenant_id' => $attributes['tenant_id'],
            ])->id,
            'reason' => fake()->sentence(),
            'status' => PlanChangeRequestStatus::Pending,
        ];
    }

    /**
     * Indicate that a super admin declined the request.
     */
    public function declined(): static
    {
        return $this->state(fn () => [
            'pending_for_tenant_id' => null,
            'status' => PlanChangeRequestStatus::Declined,
            'decided_at' => now(),
            'decision_reason' => fake()->sentence(),
        ]);
    }

    /**
     * Indicate that a super admin approved the request.
     */
    public function approved(): static
    {
        return $this->state(fn () => [
            'pending_for_tenant_id' => null,
            'status' => PlanChangeRequestStatus::Approved,
            'decided_at' => now(),
        ]);
    }

    /**
     * Indicate that the agency admin withdrew the request.
     */
    public function withdrawn(): static
    {
        return $this->state(fn () => [
            'pending_for_tenant_id' => null,
            'status' => PlanChangeRequestStatus::Withdrawn,
            'decided_at' => now(),
        ]);
    }
}
