<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * A live monthly subscription that occupies its tenant's active slot.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsOn = now()->startOfDay();

        return [
            'tenant_id' => Tenant::factory(),
            'active_for_tenant_id' => fn (array $attributes): mixed => $attributes['tenant_id'],
            'plan_name' => 'Agency Standard',
            'amount_minor' => 250000,
            'currency' => 'PHP',
            'interval' => BillingInterval::Monthly,
            'status' => SubscriptionStatus::Active,
            'starts_on' => $startsOn,
            'current_period_start_on' => $startsOn,
            'current_period_end_on' => $startsOn->copy()->addMonth()->subDay(),
            'next_billing_on' => $startsOn->copy()->addMonth(),
        ];
    }

    /**
     * Indicate that the subscription has lapsed and freed its tenant's active slot.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'active_for_tenant_id' => null,
            'status' => SubscriptionStatus::Expired,
            'next_billing_on' => null,
            'ends_on' => now()->subDay()->startOfDay(),
        ]);
    }
}
