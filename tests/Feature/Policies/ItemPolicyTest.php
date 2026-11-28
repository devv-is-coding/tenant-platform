<?php

use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Ask the item policy as the user, from within the agency, about a new draft
 * listing (or the Item class for the abilities that take none).
 */
function itemPolicyAnswer(Tenant $tenant, User $user, string $ability): Response
{
    return withinTenant($tenant, fn (): Response => Gate::forUser($user)->inspect(
        $ability,
        in_array($ability, ['viewAny', 'viewApprovalQueue'], true) ? Item::class : Item::factory()->create(),
    ));
}

test('staff may send a listing for approval, but only managers and the admin decide, publish and see the queue', function (Closure $makeUser, bool $isStaff, bool $decides) {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $user = $makeUser($tenant);

    expect([
        'viewAny' => itemPolicyAnswer($tenant, $user, 'viewAny')->allowed(),
        'submit' => itemPolicyAnswer($tenant, $user, 'submit')->allowed(),
        'viewApprovalQueue' => itemPolicyAnswer($tenant, $user, 'viewApprovalQueue')->allowed(),
        'decide' => itemPolicyAnswer($tenant, $user, 'decide')->allowed(),
        'publish' => itemPolicyAnswer($tenant, $user, 'publish')->allowed(),
    ])->toBe([
        'viewAny' => $isStaff,
        'submit' => $isStaff,
        'viewApprovalQueue' => $decides,
        'decide' => $decides,
        'publish' => $decides,
    ]);
})->with([
    'its admin' => [fn (Tenant $tenant) => User::factory()->role(Role::Admin)->for($tenant)->create(), true, true],
    'its manager' => [fn (Tenant $tenant) => User::factory()->role(Role::Manager)->for($tenant)->create(), true, true],
    'its staff' => [fn (Tenant $tenant) => User::factory()->role(Role::Staff)->for($tenant)->create(), true, false],
    'its customer' => [fn (Tenant $tenant) => User::factory()->role(Role::Customer)->for($tenant)->create(), false, false],
    'another agency\'s manager' => [fn () => User::factory()->role(Role::Manager)->for(tenantOnDomain(tier: PlanTier::Basic))->create(), false, false],
    'a super admin' => [fn () => User::factory()->superAdmin()->create(), false, false],
]);

test('without Catalog in its plan the agency still sees its queue, but nothing in it moves', function () {
    $tenant = tenantOnDomain(tier: null);
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();

    $decide = itemPolicyAnswer($tenant, $manager, 'decide');

    expect(itemPolicyAnswer($tenant, $manager, 'viewApprovalQueue')->allowed())->toBeTrue()
        ->and($decide->allowed())->toBeFalse()
        ->and($decide->message())->toBe('Catalog is available on Basic.')
        ->and(itemPolicyAnswer($tenant, $manager, 'submit')->allowed())->toBeFalse()
        ->and(itemPolicyAnswer($tenant, $manager, 'publish')->allowed())->toBeFalse();
});

test('a read-only agency\'s manager sees the queue but cannot decide', function () {
    $tenant = tenantOnDomain(TenantStatus::Suspended, PlanTier::Basic);
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();

    $decide = itemPolicyAnswer($tenant, $manager, 'decide');

    expect(itemPolicyAnswer($tenant, $manager, 'viewApprovalQueue')->allowed())->toBeTrue()
        ->and($decide->allowed())->toBeFalse()
        ->and($decide->message())->toBe('This agency is suspended, so its records are read-only.');
});
