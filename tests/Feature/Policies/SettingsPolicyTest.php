<?php

use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Settings;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Ask the settings policy as the user, from within the agency.
 */
function settingsPolicyAnswer(Tenant $tenant, User $user, string $ability): Response
{
    return withinTenant($tenant, fn (): Response => Gate::forUser($user)->inspect(
        $ability,
        $ability === 'viewAny' ? Settings::class : Settings::current(),
    ));
}

test('only the agency\'s own admin may open and change its settings', function (Closure $makeUser, bool $allowed) {
    $tenant = tenantOnDomain();
    $user = $makeUser($tenant);

    expect(settingsPolicyAnswer($tenant, $user, 'viewAny')->allowed())->toBe($allowed)
        ->and(settingsPolicyAnswer($tenant, $user, 'update')->allowed())->toBe($allowed);
})->with([
    'its admin' => [fn (Tenant $tenant) => User::factory()->role(Role::Admin)->for($tenant)->create(), true],
    'its manager' => [fn (Tenant $tenant) => User::factory()->role(Role::Manager)->for($tenant)->create(), false],
    'its staff' => [fn (Tenant $tenant) => User::factory()->role(Role::Staff)->for($tenant)->create(), false],
    'its customer' => [fn (Tenant $tenant) => User::factory()->role(Role::Customer)->for($tenant)->create(), false],
    'another agency\'s admin' => [fn () => User::factory()->role(Role::Admin)->for(tenantOnDomain())->create(), false],
    'a super admin' => [fn () => User::factory()->superAdmin()->create(), false],
]);

test('settings are never plan-gated: an agency with no live plan still changes them', function () {
    $tenant = tenantOnDomain(tier: null);

    expect(settingsPolicyAnswer($tenant, User::factory()->role(Role::Admin)->for($tenant)->create(), 'update')->allowed())->toBeTrue();
});

test('a read-only agency\'s admin can open its settings but not change them', function () {
    $tenant = tenantOnDomain(TenantStatus::Suspended);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();

    $update = settingsPolicyAnswer($tenant, $admin, 'update');

    expect(settingsPolicyAnswer($tenant, $admin, 'viewAny')->allowed())->toBeTrue()
        ->and($update->allowed())->toBeFalse()
        ->and($update->message())->toBe('This agency is suspended, so its records are read-only.');
});

test('the settings page is refused outside an agency', function () {
    $admin = User::factory()->role(Role::Admin)->for(tenantOnDomain())->create();

    expect(Gate::forUser($admin)->allows('viewAny', Settings::class))->toBeFalse();
});

test('the admin of an agency on a plan with Branded app may change its branding', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);

    expect(settingsPolicyAnswer($tenant, User::factory()->role(Role::Admin)->for($tenant)->create(), 'updateBranding')->allowed())->toBeTrue();
});

test('without Branded app the branding cannot be changed, though the operating defaults still can', function () {
    $tenant = tenantOnDomain(tier: null);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();

    $branding = settingsPolicyAnswer($tenant, $admin, 'updateBranding');

    expect($branding->allowed())->toBeFalse()
        ->and($branding->message())->toBe('Branded app is available on Basic.')
        ->and(settingsPolicyAnswer($tenant, $admin, 'update')->allowed())->toBeTrue();
});

test('only the agency\'s own admin may change its branding, and not while it is read-only', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Pro);
    $suspended = tenantOnDomain(TenantStatus::Suspended, PlanTier::Pro);

    expect(settingsPolicyAnswer($tenant, User::factory()->role(Role::Manager)->for($tenant)->create(), 'updateBranding')->allowed())->toBeFalse()
        ->and(settingsPolicyAnswer($suspended, User::factory()->role(Role::Admin)->for($suspended)->create(), 'updateBranding')->allowed())->toBeFalse();
});
