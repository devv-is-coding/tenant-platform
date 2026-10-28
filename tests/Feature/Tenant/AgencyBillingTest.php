<?php

use App\Actions\Subscriptions\MovePlanFeatureAction;
use App\Enums\BillingInterval;
use App\Enums\PlanFeature;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Open the agency's plan & billing page as the user, from within the agency.
 */
function agencyBilling(Tenant $tenant, User $user): Testable
{
    tenancy()->initialize($tenant);

    return Livewire::actingAs($user)->test('pages::tenant.billing');
}

/**
 * Open the plan & billing page as a new admin of the agency.
 */
function agencyBillingAsAdmin(Tenant $tenant): Testable
{
    return agencyBilling($tenant, User::factory()->role(Role::Admin)->for($tenant)->create());
}

test('the admin opens the page on the agency\'s domain; managers and staff are refused', function (Role $role) {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);

    actingAs(User::factory()->role(Role::Admin)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/billing")
        ->assertOk()
        ->assertSee('Plan & billing');

    tenancy()->end();

    actingAs(User::factory()->role($role)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/billing")
        ->assertForbidden();
})->with([
    'manager' => Role::Manager,
    'staff' => Role::Staff,
]);

test('the live plan shows its tier, name, price, interval, start and next billing', function () {
    travelTo('2026-10-28 09:00:00');
    $tenant = tenantOnDomain();
    Subscription::factory()->for($tenant)->create([
        'plan_name' => 'Agency Advanced Quarterly',
        'tier' => PlanTier::Advanced,
        'amount_minor' => 1200000,
        'interval' => BillingInterval::Quarterly,
        'status' => SubscriptionStatus::Active,
        'starts_on' => '2026-08-01',
        'next_billing_on' => '2026-11-01',
    ]);

    agencyBillingAsAdmin($tenant)->assertSeeInOrder([
        'Agency Advanced Quarterly', 'Advanced', 'Active',
        'Price', '₱12,000.00', 'Billed', 'Quarterly', 'Started', 'Aug 1, 2026', 'Next billing', 'Nov 1, 2026',
    ]);
});

test('an agency with no live plan reads "No plan", and every tier shows what it would add', function () {
    $tenant = tenantOnDomain();
    Subscription::factory()->for($tenant)->expired()->create(['plan_name' => 'Old Plan']);

    $page = agencyBillingAsAdmin($tenant)
        ->assertSee('No plan')
        ->assertDontSee('Old Plan');

    expect($page->instance()->features)->toBe([])
        ->and(array_map(fn (array $upgrade): PlanTier => $upgrade['tier'], $page->instance()->upgrades))
        ->toBe([PlanTier::Basic, PlanTier::Advanced, PlanTier::Pro]);
});

test('the plan lists what it includes and, tier by tier, what an upgrade would add', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Advanced);

    $page = agencyBillingAsAdmin($tenant);
    $upgrades = $page->instance()->upgrades;

    expect($page->instance()->features)->toContain(PlanFeature::Team, PlanFeature::Reviews)
        ->and(array_map(fn (array $upgrade): PlanTier => $upgrade['tier'], $upgrades))->toBe([PlanTier::Pro])
        ->and($upgrades[0]['features'])->not->toContain(PlanFeature::Reviews);
});

test('a feature the super admin moves shows on the next load, with nothing cached', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $page = agencyBillingAsAdmin($tenant);
    expect($page->instance()->features)->toContain(PlanFeature::Team);

    app(MovePlanFeatureAction::class)->handle(User::factory()->superAdmin()->create(), PlanFeature::Team, [
        'minimum_tier' => PlanTier::Advanced->value,
        'move_reason' => 'Team accounts become an Advanced feature.',
    ]);

    $page->call('$refresh');

    expect($page->instance()->features)->not->toContain(PlanFeature::Team)
        ->and($page->instance()->upgrades[0]['tier'])->toBe(PlanTier::Advanced)
        ->and($page->instance()->upgrades[0]['features'])->toContain(PlanFeature::Team);
});

test('the invoices show amount, paid, balance, due and status, overdue marked, with the balance owed', function () {
    travelTo('2026-10-28 09:00:00');
    $tenant = tenantOnDomain();
    $subscription = Subscription::factory()->for($tenant)->create();
    SubscriptionInvoice::factory()->for($subscription)->overdue()->partiallyPaid(100000)->create(['reference' => 'PLAT-OWED-1', 'amount_minor' => 250000]);
    SubscriptionInvoice::factory()->for($subscription)->paid()->create(['reference' => 'PLAT-PAID-1', 'amount_minor' => 250000, 'period_start_on' => '2026-08-01']);

    agencyBillingAsAdmin($tenant)
        ->assertSeeInOrder(['Balance owed', '₱1,500.00'])
        ->assertSeeInOrder(['PLAT-OWED-1', '₱2,500.00', '₱1,000.00', '₱1,500.00', 'Partially paid', 'Overdue'])
        ->assertSeeInOrder(['PLAT-PAID-1', '₱2,500.00', '₱2,500.00', '₱0.00', 'Paid']);
});

test('the payments list what the agency paid, a failed one marked', function () {
    $tenant = tenantOnDomain();
    $invoice = SubscriptionInvoice::factory()->for(Subscription::factory()->for($tenant))->create(['reference' => 'PLAT-INV-7']);
    SubscriptionPayment::factory()->for($invoice, 'invoice')->create(['reference' => 'PLATPAY-OK', 'amount_minor' => 100000]);
    SubscriptionPayment::factory()->for($invoice, 'invoice')->failed()->create(['reference' => 'PLATPAY-FAILED']);

    agencyBillingAsAdmin($tenant)
        ->assertSeeInOrder(['Payments', 'PLATPAY-OK', 'PLAT-INV-7', 'Bank transfer', '₱1,000.00'])
        ->assertSeeInOrder(['PLATPAY-FAILED', 'Failed']);
});

test('another agency\'s subscription, invoices and payments never appear', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $other = tenantOnDomain();
    $theirInvoice = SubscriptionInvoice::factory()->for(Subscription::factory()->for($other)->create(['plan_name' => 'Their Plan']))->create(['reference' => 'PLAT-THEIRS']);
    SubscriptionPayment::factory()->for($theirInvoice, 'invoice')->create(['reference' => 'PLATPAY-THEIRS']);

    agencyBillingAsAdmin($tenant)
        ->assertDontSee('Their Plan')
        ->assertDontSee('PLAT-THEIRS')
        ->assertDontSee('PLATPAY-THEIRS')
        ->assertSeeInOrder(['Balance owed', '₱0.00']);
});

test('a closed agency with no plan still sees its plan and what it owes', function () {
    $tenant = tenantOnDomain(TenantStatus::Closed);
    SubscriptionInvoice::factory()->for(Subscription::factory()->for($tenant)->expired())->overdue()->create(['reference' => 'PLAT-STILL-OWED']);

    agencyBillingAsAdmin($tenant)
        ->assertSee('No plan')
        ->assertSee('PLAT-STILL-OWED');
});

test('the page component refuses anyone but the agency\'s admin, without the route middleware', function (Closure $makeUser) {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);

    agencyBilling($tenant, $makeUser($tenant))->assertForbidden();
})->with([
    'its manager' => [fn (Tenant $tenant) => User::factory()->role(Role::Manager)->for($tenant)->create()],
    'another agency\'s admin' => [fn () => User::factory()->role(Role::Admin)->for(tenantOnDomain())->create()],
    'a super admin' => [fn () => User::factory()->superAdmin()->create()],
]);
