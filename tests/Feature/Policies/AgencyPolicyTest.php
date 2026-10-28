<?php

use App\Enums\PlanFeature;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Settings;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\AgencyPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/*
 * A stand-in extends AgencyPolicy over the agency's Settings, so each layer
 * can be switched on alone; SettingsPolicyTest and UserPolicyTest cover the
 * real policies. Its own rules are the kind real policies state: anyone may
 * look, only the Admin may change.
 */

/**
 * Register the stand-in policy for Settings, gated by the feature.
 */
function useStandInAgencyPolicy(?PlanFeature $feature): void
{
    $policy = new class extends AgencyPolicy
    {
        public static ?PlanFeature $gatedBy = null;

        protected function feature(): ?PlanFeature
        {
            return self::$gatedBy;
        }

        public function view(User $user, Settings $settings): bool
        {
            return true;
        }

        public function update(User $user, Settings $settings): bool
        {
            return $user->role === Role::Admin;
        }
    };
    $policy::$gatedBy = $feature;
    Gate::policy(Settings::class, $policy::class);
}

/**
 * Ask the stand-in policy, gated by the feature, as a new user of the agency
 * with the role, from within the agency.
 */
function agencyPolicyAnswer(Tenant $tenant, Role $role, string $ability, ?PlanFeature $feature = PlanFeature::Reviews): Response
{
    useStandInAgencyPolicy($feature);
    $user = User::factory()->role($role)->for($tenant)->create();

    return withinTenant($tenant, fn (): Response => Gate::forUser($user)->inspect($ability, Settings::sole()));
}

test('a read-only agency\'s records can be read but not changed, whatever its plan', function (TenantStatus $status) {
    $tenant = tenantOnDomain($status, PlanTier::Pro);

    $update = agencyPolicyAnswer($tenant, Role::Admin, 'update');

    expect(agencyPolicyAnswer($tenant, Role::Admin, 'view')->allowed())->toBeTrue()
        ->and($update->allowed())->toBeFalse()
        ->and($update->message())->toBe('This agency is '.mb_strtolower($status->label()).', so its records are read-only.');
})->with([
    'paused' => TenantStatus::Paused,
    'suspended' => TenantStatus::Suspended,
    'closed' => TenantStatus::Closed,
]);

test('an agency whose tier lacks the feature can read its records but not change them', function (?PlanTier $tier) {
    $tenant = tenantOnDomain(tier: $tier);

    $update = agencyPolicyAnswer($tenant, Role::Admin, 'update');

    expect(agencyPolicyAnswer($tenant, Role::Admin, 'view')->allowed())->toBeTrue()
        ->and($update->allowed())->toBeFalse()
        ->and($update->message())->toBe('Reviews & wishlist is available on Advanced.');
})->with([
    'downgraded to a lower tier' => PlanTier::Basic,
    'no live plan' => null,
]);

test('an active agency with the feature gets the policy\'s own answer', function (Role $role, bool $allowed) {
    $tenant = tenantOnDomain(tier: PlanTier::Advanced);

    expect(agencyPolicyAnswer($tenant, $role, 'update')->allowed())->toBe($allowed);
})->with([
    'the admin, whom the policy allows' => [Role::Admin, true],
    'staff, whom the policy refuses' => [Role::Staff, false],
]);

test('records that are never plan-gated can be changed without a plan', function () {
    $tenant = tenantOnDomain();

    expect(agencyPolicyAnswer($tenant, Role::Admin, 'update', feature: null)->allowed())->toBeTrue();
});

test('nothing can be changed from outside an agency', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Pro);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    useStandInAgencyPolicy(PlanFeature::Reviews);
    $settings = withinTenant($tenant, fn (): Settings => Settings::sole());

    $update = Gate::forUser($admin)->inspect('update', $settings);

    expect($update->allowed())->toBeFalse()
        ->and($update->message())->toBe('Agency records can only be changed from within the agency.');
});
