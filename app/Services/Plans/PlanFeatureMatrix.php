<?php

namespace App\Services\Plans;

use App\Enums\PlanFeature;
use App\Enums\PlanTier;
use App\Models\PlanFeatureSetting;

/**
 * Which tier each plan feature starts at: the super admin's override where one
 * exists, otherwise the feature's defaultTier().
 *
 * Read live rather than snapshotted per subscription, so moving a feature
 * reaches every agency at once. Bound as a scoped singleton, so the overrides
 * are read once per request or job, however many gates ask.
 */
class PlanFeatureMatrix
{
    /**
     * The tier every feature starts at, keyed by feature value, once read.
     *
     * @var array<string, PlanTier>|null
     */
    private ?array $minimumTiers = null;

    /**
     * Get the tier the feature starts at.
     */
    public function minimumTierFor(PlanFeature $feature): PlanTier
    {
        return $this->minimumTiers()[$feature->value];
    }

    /**
     * Get the tier every feature starts at, in the enum's order.
     *
     * @return array<string, PlanTier>
     */
    public function minimumTiers(): array
    {
        if ($this->minimumTiers !== null) {
            return $this->minimumTiers;
        }

        // Raw values rather than models, so a row left by a feature since
        // removed from the enum is ignored instead of failing every gate.
        $overrides = PlanFeatureSetting::query()->toBase()->pluck('minimum_tier', 'feature');

        $minimumTiers = [];

        foreach (PlanFeature::cases() as $feature) {
            $override = $overrides[$feature->value] ?? null;

            $minimumTiers[$feature->value] = $override === null ? $feature->defaultTier() : PlanTier::from((int) $override);
        }

        return $this->minimumTiers = $minimumTiers;
    }

    /**
     * Determine if the tier includes the feature.
     */
    public function includes(PlanTier $tier, PlanFeature $feature): bool
    {
        return $tier->includes($this->minimumTierFor($feature));
    }

    /**
     * Get every feature the tier includes, in the enum's order.
     *
     * @return list<PlanFeature>
     */
    public function featuresFor(PlanTier $tier): array
    {
        return array_values(array_filter(
            PlanFeature::cases(),
            fn (PlanFeature $feature): bool => $this->includes($tier, $feature),
        ));
    }

    /**
     * Forget the overrides read so far, after one has changed.
     */
    public function forget(): void
    {
        $this->minimumTiers = null;
    }
}
