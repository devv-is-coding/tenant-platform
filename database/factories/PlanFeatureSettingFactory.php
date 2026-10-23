<?php

namespace Database\Factories;

use App\Enums\PlanFeature;
use App\Enums\PlanTier;
use App\Models\PlanFeatureSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanFeatureSetting>
 */
class PlanFeatureSettingFactory extends Factory
{
    /**
     * A feature moved to a tier other than its default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $feature = fake()->unique()->randomElement(PlanFeature::cases());

        return [
            'feature' => $feature,
            'minimum_tier' => fake()->randomElement(array_values(array_filter(
                PlanTier::cases(),
                fn (PlanTier $tier): bool => $tier !== $feature->defaultTier(),
            ))),
        ];
    }
}
