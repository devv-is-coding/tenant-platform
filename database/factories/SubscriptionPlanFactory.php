<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    /**
     * A monthly plan in the platform's price list.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'amount_minor' => fake()->numberBetween(1, 50) * 10000,
            'currency' => 'PHP',
            'interval' => BillingInterval::Monthly,
        ];
    }
}
