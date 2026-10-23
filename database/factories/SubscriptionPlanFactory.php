<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\PlanTier;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    /**
     * A monthly Basic plan in the platform's price list.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'tier' => PlanTier::Basic,
            'amount_minor' => fake()->numberBetween(1, 50) * 10000,
            'currency' => 'PHP',
            'interval' => BillingInterval::Monthly,
        ];
    }
}
