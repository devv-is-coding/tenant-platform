<?php

use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Open the agency's dashboard as the user, from within the agency.
 */
function agencyDashboard(Tenant $tenant, User $user): Testable
{
    tenancy()->initialize($tenant);

    return Livewire::actingAs($user)->test('pages::tenant.dashboard');
}

test('the admin sees the plan, the team and what needs attention', function () {
    $tenant = tenantOnDomain();

    agencyDashboard($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->assertSeeHtml('data-test="plan-card"')
        ->assertSeeHtml('data-test="team-card"')
        ->assertSeeHtml('data-test="needs-attention-card"');
});

test('managers and staff see only the agency\'s status, none of the admin\'s cards', function (Role $role) {
    $tenant = tenantOnDomain(tier: PlanTier::Pro);

    agencyDashboard($tenant, User::factory()->role($role)->for($tenant)->create())
        ->assertSee('Agency status')
        ->assertSee('Nothing to act on yet')
        ->assertDontSeeHtml('data-test="plan-card"')
        ->assertDontSeeHtml('data-test="team-card"')
        ->assertDontSeeHtml('data-test="needs-attention-card"');
})->with([
    'manager' => Role::Manager,
    'staff' => Role::Staff,
]);

test('the team card counts the agency\'s own staff by role, active apart from deactivated', function () {
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    User::factory()->role(Role::Admin)->for($tenant)->inactive()->create();
    User::factory()->role(Role::Manager)->for($tenant)->count(2)->create();
    User::factory()->role(Role::Staff)->for($tenant)->count(3)->create();
    User::factory()->role(Role::Staff)->for($tenant)->inactive()->create();
    User::factory()->role(Role::Customer)->for($tenant)->count(4)->create();
    User::factory()->role(Role::Staff)->for(tenantOnDomain())->create();

    $dashboard = agencyDashboard($tenant, $admin);

    expect($dashboard->instance()->team)->toBe([
        ['label' => 'Admins', 'active' => 1, 'deactivated' => 1],
        ['label' => 'Managers', 'active' => 2, 'deactivated' => 0],
        ['label' => 'Staff', 'active' => 3, 'deactivated' => 1],
    ])->and($dashboard->instance()->activeStaff)->toBe(6);
});

test('the plan card shows the live tier and subscription status, and a past-due one needs attention', function () {
    $tenant = tenantOnDomain();
    Subscription::factory()->for($tenant)->create(['plan_name' => 'Agency Pro Monthly', 'tier' => PlanTier::Pro, 'status' => SubscriptionStatus::PastDue]);

    agencyDashboard($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->assertSeeInOrder(['Plan', 'Pro', 'Past due', 'Agency Pro Monthly'])
        ->assertSeeInOrder(['Needs attention', 'Subscription past due']);
});

test('an agency with no live plan says so, and it needs attention', function () {
    $tenant = tenantOnDomain();
    Subscription::factory()->for($tenant)->expired()->create(['plan_name' => 'Old Plan']);

    agencyDashboard($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->assertSeeInOrder(['Plan', 'No plan'])
        ->assertSeeInOrder(['Needs attention', 'No live plan'])
        ->assertDontSee('Old Plan');
});

test('an active agency on a plan has nothing needing attention', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);

    agencyDashboard($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->assertSee('Nothing needs a decision right now.');
});

test('the dashboard component refuses anyone but the agency\'s own staff, without the route middleware', function (Closure $makeUser) {
    $tenant = tenantOnDomain();

    agencyDashboard($tenant, $makeUser($tenant))->assertForbidden();
})->with([
    'another agency\'s admin' => [fn () => User::factory()->role(Role::Admin)->for(tenantOnDomain())->create()],
    'the agency\'s own customer' => [fn (Tenant $tenant) => User::factory()->role(Role::Customer)->for($tenant)->create()],
    'a super admin' => [fn () => User::factory()->superAdmin()->create()],
]);
