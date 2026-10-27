<?php

namespace App\Policies;

use App\Enums\PlanFeature;
use App\Enums\Role;
use App\Models\Settings;
use App\Models\User;

/**
 * The agency's settings are its Admin's to read and change, and AgencyPolicy
 * makes them read-only while the agency is.
 *
 * The operating defaults are never plan-gated: every tier sets its own. The
 * app branding needs BrandedApp to save; what was saved stays when the agency
 * loses the feature, and the app-config endpoint serves the platform's
 * defaults in its place.
 */
class SettingsPolicy extends AgencyPolicy
{
    /**
     * Get the plan feature the operating defaults belong to: none.
     */
    protected function feature(): ?PlanFeature
    {
        return null;
    }

    /**
     * Get the plan feature one ability needs: branding needs BrandedApp.
     */
    protected function featureFor(string $ability): ?PlanFeature
    {
        return $ability === 'updateBranding' ? PlanFeature::BrandedApp : $this->feature();
    }

    /**
     * Determine whether the user can open the agency's settings and branding
     * pages.
     */
    public function viewAny(User $user): bool
    {
        return $this->isAdminOfCurrentAgency($user);
    }

    /**
     * Determine whether the user can change the agency's operating defaults.
     */
    public function update(User $user, Settings $settings): bool
    {
        return $this->isAdminOfCurrentAgency($user);
    }

    /**
     * Determine whether the user can change the agency's app branding.
     */
    public function updateBranding(User $user, Settings $settings): bool
    {
        return $this->isAdminOfCurrentAgency($user);
    }

    /**
     * Determine if the user is an admin of the agency of the current request.
     */
    private function isAdminOfCurrentAgency(User $user): bool
    {
        return $user->role === Role::Admin
            && tenancy()->initialized
            && $user->tenant_id === tenant('id');
    }
}
