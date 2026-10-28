<?php

use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/*
 * The agency's side of UserPolicy: its Admin manages its managers and staff.
 * The console's side (delete, restore) is covered by DeleteRecordsTest and
 * RestoreRecordsTest.
 */

/**
 * Ask the user policy as the user about the model (or about adding one),
 * from within the agency.
 */
function teamPolicyAnswer(Tenant $tenant, User $user, string $ability, ?User $model = null): Response
{
    return withinTenant($tenant, fn (): Response => Gate::forUser($user)->inspect($ability, $model ?? User::class));
}

test('the agency\'s admin manages its managers and staff', function (Role $role) {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $active = User::factory()->role($role)->for($tenant)->create();
    $deactivated = User::factory()->role($role)->for($tenant)->inactive()->create();

    expect(teamPolicyAnswer($tenant, $admin, 'create')->allowed())->toBeTrue()
        ->and(teamPolicyAnswer($tenant, $admin, 'update', $active)->allowed())->toBeTrue()
        ->and(teamPolicyAnswer($tenant, $admin, 'deactivate', $active)->allowed())->toBeTrue()
        ->and(teamPolicyAnswer($tenant, $admin, 'reactivate', $active)->allowed())->toBeFalse()
        ->and(teamPolicyAnswer($tenant, $admin, 'deactivate', $deactivated)->allowed())->toBeFalse()
        ->and(teamPolicyAnswer($tenant, $admin, 'reactivate', $deactivated)->allowed())->toBeTrue();
})->with([
    'manager' => Role::Manager,
    'staff' => Role::Staff,
]);

test('the admin cannot touch an admin, a customer, another agency\'s staff or a deleted account', function (Closure $makeModel) {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $model = $makeModel($tenant);

    foreach (['update', 'deactivate'] as $ability) {
        expect(teamPolicyAnswer($tenant, $admin, $ability, $model)->allowed())->toBeFalse();
    }
})->with([
    'another admin' => [fn (Tenant $tenant) => User::factory()->role(Role::Admin)->for($tenant)->create()],
    'a customer' => [fn (Tenant $tenant) => User::factory()->role(Role::Customer)->for($tenant)->create()],
    'another agency\'s staff' => [fn () => User::factory()->role(Role::Staff)->for(tenantOnDomain())->create()],
    'a deleted staff account' => [fn (Tenant $tenant) => tap(User::factory()->role(Role::Staff)->for($tenant)->create())->delete()],
]);

test('only the agency\'s admin manages the team', function (Closure $makeUser) {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $user = $makeUser($tenant);
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();

    expect(teamPolicyAnswer($tenant, $user, 'create')->allowed())->toBeFalse()
        ->and(teamPolicyAnswer($tenant, $user, 'update', $staff)->allowed())->toBeFalse()
        ->and(teamPolicyAnswer($tenant, $user, 'deactivate', $staff)->allowed())->toBeFalse();
})->with([
    'its manager' => [fn (Tenant $tenant) => User::factory()->role(Role::Manager)->for($tenant)->create()],
    'its staff' => [fn (Tenant $tenant) => User::factory()->role(Role::Staff)->for($tenant)->create()],
    'another agency\'s admin' => [fn () => User::factory()->role(Role::Admin)->for(tenantOnDomain(tier: PlanTier::Basic))->create()],
    'a super admin' => [fn () => User::factory()->superAdmin()->create()],
]);

test('without the Team feature the admin cannot add, edit or reactivate, but can still deactivate', function () {
    $tenant = tenantOnDomain(tier: null);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $active = User::factory()->role(Role::Staff)->for($tenant)->create();
    $deactivated = User::factory()->role(Role::Staff)->for($tenant)->inactive()->create();

    $create = teamPolicyAnswer($tenant, $admin, 'create');

    expect($create->allowed())->toBeFalse()
        ->and($create->message())->toBe('Team accounts is available on Basic.')
        ->and(teamPolicyAnswer($tenant, $admin, 'update', $active)->allowed())->toBeFalse()
        ->and(teamPolicyAnswer($tenant, $admin, 'reactivate', $deactivated)->allowed())->toBeFalse()
        ->and(teamPolicyAnswer($tenant, $admin, 'deactivate', $active)->allowed())->toBeTrue();
});

test('a read-only agency\'s admin cannot add, edit or reactivate, but can still deactivate', function (TenantStatus $status) {
    $tenant = tenantOnDomain($status, PlanTier::Pro);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $active = User::factory()->role(Role::Staff)->for($tenant)->create();
    $deactivated = User::factory()->role(Role::Staff)->for($tenant)->inactive()->create();

    expect(teamPolicyAnswer($tenant, $admin, 'create')->allowed())->toBeFalse()
        ->and(teamPolicyAnswer($tenant, $admin, 'update', $active)->allowed())->toBeFalse()
        ->and(teamPolicyAnswer($tenant, $admin, 'reactivate', $deactivated)->allowed())->toBeFalse()
        ->and(teamPolicyAnswer($tenant, $admin, 'deactivate', $active)->allowed())->toBeTrue();
})->with([
    'paused' => TenantStatus::Paused,
    'suspended' => TenantStatus::Suspended,
    'closed' => TenantStatus::Closed,
]);

test('the team cannot be changed from outside an agency, deactivating included', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();

    expect(Gate::forUser($admin)->allows('create', User::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('deactivate', $staff))->toBeFalse();
});
