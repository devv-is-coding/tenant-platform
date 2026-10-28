<?php

use App\Enums\Role;
use App\Models\PlanChangeRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;

beforeEach(function () {
    // Plan requests are central, so no tenant database is provisioned.
    Event::fake([TenantCreated::class]);
});

test('only a super admin may list and decide every agency\'s plan requests', function (Role $role, bool $allowed) {
    $request = PlanChangeRequest::factory()->create();
    $user = User::factory()->role($role)->for($request->tenant)->create();

    expect($user->can('viewAny', PlanChangeRequest::class))->toBe($allowed)
        ->and($user->can('decide', $request))->toBe($allowed);
})->with([
    'a super admin' => [Role::SuperAdmin, true],
    'the asking agency\'s admin' => [Role::Admin, false],
    'its manager' => [Role::Manager, false],
]);

test('only the agency\'s own admin may ask for a plan for it', function (Closure $makeUser, bool $allowed) {
    $tenant = Tenant::factory()->create();

    expect($makeUser($tenant)->can('create', [PlanChangeRequest::class, $tenant]))->toBe($allowed);
})->with([
    'its admin' => [fn (Tenant $tenant) => User::factory()->role(Role::Admin)->for($tenant)->create(), true],
    'its manager' => [fn (Tenant $tenant) => User::factory()->role(Role::Manager)->for($tenant)->create(), false],
    'its staff' => [fn (Tenant $tenant) => User::factory()->role(Role::Staff)->for($tenant)->create(), false],
    'another agency\'s admin' => [fn () => User::factory()->role(Role::Admin)->for(Tenant::factory())->create(), false],
    'a super admin' => [fn () => User::factory()->superAdmin()->create(), false],
]);

test('the asking agency\'s admin may withdraw a request only while it waits, and never another agency\'s', function () {
    $pending = PlanChangeRequest::factory()->create();
    $declined = PlanChangeRequest::factory()->for($pending->tenant)->declined()->create();
    $admin = User::factory()->role(Role::Admin)->for($pending->tenant)->create();
    $otherAdmin = User::factory()->role(Role::Admin)->for(Tenant::factory())->create();

    expect($admin->can('withdraw', $pending))->toBeTrue()
        ->and($admin->can('withdraw', $declined))->toBeFalse()
        ->and($otherAdmin->can('withdraw', $pending))->toBeFalse();
});
