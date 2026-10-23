<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\PlanTier;
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
     * A live monthly Basic subscription that occupies its tenant's active slot.
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
            'tier' => PlanTier::Basic,
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
     * Indicate that one of the subscription's invoices went unpaid past its due date.
     */
    public function pastDue(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::PastDue,
        ]);
    }

    /**
     * Indicate that the subscription was cancelled today and freed its tenant's active slot.
     */
    public function cancelled(): static
    {
        return $this->state(fn () => [
            'active_for_tenant_id' => null,
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
            'next_billing_on' => null,
            'ends_on' => now()->startOfDay(),
        ]);
    }

    /**
     * Indicate that the subscription has lapsed and freed its tenant's active slot.
     */
    public function expired(): static
    {
        return $this->state(fn () => [
            'active_for_tenant_id' => null,
            'status' => SubscriptionStatus::Expired,
            'next_billing_on' => null,
            'ends_on' => now()->subDay()->startOfDay(),
        ]);
    }
}
