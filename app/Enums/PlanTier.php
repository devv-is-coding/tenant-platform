<?php

namespace App\Enums;

/**
 * Backed by the `subscription_plans.tier`, `subscriptions.tier` and
 * `plan_features.minimum_tier` tinyint columns.
 *
 * What an agency pays for: a tier of features, never its locality. Tiers are
 * cumulative, so the backing values are ordered and a higher tier includes
 * every feature of the ones below it.
 */
enum PlanTier: int
{
    case Basic = 1;
    case Advanced = 2;
    case Pro = 3;

    /**
     * Get the human-readable label for the tier.
     */
    public function label(): string
    {
        return match ($this) {
            self::Basic => __('Basic'),
            self::Advanced => __('Advanced'),
            self::Pro => __('Pro'),
        };
    }

    /**
     * Get the one-line pitch the console shows beside the tier.
     */
    public function pitch(): string
    {
        return match ($this) {
            self::Basic => __('Run the agency'),
            self::Advanced => __('Sell online, run tours smoothly'),
            self::Pro => __('The luxury layer'),
        };
    }

    /**
     * Get the Flux badge color the console shows the tier in.
     */
    public function color(): string
    {
        return match ($this) {
            self::Basic => 'sky',
            self::Advanced => 'indigo',
            self::Pro => 'amber',
        };
    }

    /**
     * Determine if this tier includes everything the given tier does.
     */
    public function includes(PlanTier $other): bool
    {
        return $this->value >= $other->value;
    }
}
