<?php

use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Refund;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Ask the refund policy as the user, from within the agency, about a new
 * requested refund (or the Refund class for viewAny).
 */
function refundPolicyAnswer(Tenant $tenant, User $user, string $ability): Response
{
    return withinTenant($tenant, fn (): Response => Gate::forUser($user)->inspect(
        $ability,
        $ability === 'viewAny' ? Refund::class : Refund::factory()->create(),
    ));
}

test('only the agency\'s managers and admin see, decide and pay out its refunds', function (Closure $makeUser, bool $decides) {
    $tenant = tenantOnDomain(tier: PlanTier::Advanced);
    $user = $makeUser($tenant);

    expect(collect(['viewAny', 'decide', 'processThroughStripe', 'processByHand'])
        ->mapWithKeys(fn (string $ability): array => [$ability => refundPolicyAnswer($tenant, $user, $ability)->allowed()])
        ->all())->toBe(['viewAny' => $decides, 'decide' => $decides, 'processThroughStripe' => $decides, 'processByHand' => $decides]);
})->with([
    'its admin' => [fn (Tenant $tenant) => User::factory()->role(Role::Admin)->for($tenant)->create(), true],
    'its manager' => [fn (Tenant $tenant) => User::factory()->role(Role::Manager)->for($tenant)->create(), true],
    'its staff' => [fn (Tenant $tenant) => User::factory()->role(Role::Staff)->for($tenant)->create(), false],
    'its customer' => [fn (Tenant $tenant) => User::factory()->role(Role::Customer)->for($tenant)->create(), false],
    'another agency\'s manager' => [fn () => User::factory()->role(Role::Manager)->for(tenantOnDomain(tier: PlanTier::Advanced))->create(), false],
    'a super admin' => [fn () => User::factory()->superAdmin()->create(), false],
]);

test('on Basic a manager decides and pays back by hand, but not through Stripe', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();

    $stripe = refundPolicyAnswer($tenant, $manager, 'processThroughStripe');

    expect($stripe->allowed())->toBeFalse()
        ->and($stripe->message())->toBe('Card refunds is available on Advanced.')
        ->and(refundPolicyAnswer($tenant, $manager, 'decide')->allowed())->toBeTrue()
        ->and(refundPolicyAnswer($tenant, $manager, 'processByHand')->allowed())->toBeTrue();
});

test('with no plan a manager cannot decide, but can still pay back what was approved by hand', function () {
    $tenant = tenantOnDomain(tier: null);
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();

    expect(refundPolicyAnswer($tenant, $manager, 'decide')->allowed())->toBeFalse()
        ->and(refundPolicyAnswer($tenant, $manager, 'processByHand')->allowed())->toBeTrue();
});

test('a read-only agency\'s manager sees its refunds but cannot move them', function () {
    $tenant = tenantOnDomain(TenantStatus::Suspended, PlanTier::Advanced);
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();

    expect(refundPolicyAnswer($tenant, $manager, 'viewAny')->allowed())->toBeTrue()
        ->and(refundPolicyAnswer($tenant, $manager, 'decide')->allowed())->toBeFalse()
        ->and(refundPolicyAnswer($tenant, $manager, 'processByHand')->message())->toBe('This agency is suspended, so its records are read-only.');
});
