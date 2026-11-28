<?php

use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Ask the booking policy as the user, from within the agency, about a new
 * pending booking (or the Booking class for viewAny).
 */
function bookingPolicyAnswer(Tenant $tenant, User $user, string $ability): Response
{
    return withinTenant($tenant, fn (): Response => Gate::forUser($user)->inspect(
        $ability,
        $ability === 'viewAny' ? Booking::class : Booking::factory()->create(),
    ));
}

test('all the agency\'s staff see its bookings, but only managers and the admin decide or cancel them', function (Closure $makeUser, bool $isStaff, bool $decides) {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $user = $makeUser($tenant);

    expect([
        'viewAny' => bookingPolicyAnswer($tenant, $user, 'viewAny')->allowed(),
        'decide' => bookingPolicyAnswer($tenant, $user, 'decide')->allowed(),
        'cancel' => bookingPolicyAnswer($tenant, $user, 'cancel')->allowed(),
    ])->toBe(['viewAny' => $isStaff, 'decide' => $decides, 'cancel' => $decides]);
})->with([
    'its admin' => [fn (Tenant $tenant) => User::factory()->role(Role::Admin)->for($tenant)->create(), true, true],
    'its manager' => [fn (Tenant $tenant) => User::factory()->role(Role::Manager)->for($tenant)->create(), true, true],
    'its staff' => [fn (Tenant $tenant) => User::factory()->role(Role::Staff)->for($tenant)->create(), true, false],
    'its customer' => [fn (Tenant $tenant) => User::factory()->role(Role::Customer)->for($tenant)->create(), false, false],
    'another agency\'s manager' => [fn () => User::factory()->role(Role::Manager)->for(tenantOnDomain(tier: PlanTier::Basic))->create(), false, false],
    'a super admin' => [fn () => User::factory()->superAdmin()->create(), false, false],
]);

test('without Bookings in its plan the agency cannot approve or reject, but can still cancel to release a hold', function () {
    $tenant = tenantOnDomain(tier: null);
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();

    $decide = bookingPolicyAnswer($tenant, $manager, 'decide');

    expect($decide->allowed())->toBeFalse()
        ->and($decide->message())->toBe('Bookings desk is available on Basic.')
        ->and(bookingPolicyAnswer($tenant, $manager, 'cancel')->allowed())->toBeTrue()
        ->and(bookingPolicyAnswer($tenant, $manager, 'viewAny')->allowed())->toBeTrue();
});

test('a read-only agency\'s manager sees its bookings but can neither decide nor cancel them', function () {
    $tenant = tenantOnDomain(TenantStatus::Suspended, PlanTier::Basic);
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();

    expect(bookingPolicyAnswer($tenant, $manager, 'viewAny')->allowed())->toBeTrue()
        ->and(bookingPolicyAnswer($tenant, $manager, 'decide')->allowed())->toBeFalse()
        ->and(bookingPolicyAnswer($tenant, $manager, 'cancel')->message())->toBe('This agency is suspended, so its records are read-only.');
});
