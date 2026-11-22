<?php

use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\SubscriptionInvoiceStatus;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\travelTo;

/**
 * Open the plan & billing page as a new admin of the agency, from within it,
 * arriving with the query string given.
 *
 * @param  array<string, string>  $query
 */
function cardBillingPage(Tenant $tenant, array $query = []): Testable
{
    tenancy()->initialize($tenant);

    return Livewire::withQueryParams($query)
        ->actingAs(User::factory()->role(Role::Admin)->for($tenant)->create())
        ->test('pages::tenant.billing');
}

/**
 * Put the agency on a ₱600.00 Basic plan for November 2026, its invoice paid.
 */
function basicPaidForNovember(Tenant $tenant): Subscription
{
    $subscription = Subscription::factory()->for($tenant)->create([
        'plan_name' => 'Agency Basic Monthly',
        'tier' => PlanTier::Basic,
        'amount_minor' => 60000,
        'current_period_start_on' => '2026-11-01',
        'current_period_end_on' => '2026-11-30',
        'next_billing_on' => '2026-12-01',
    ]);
    SubscriptionInvoice::factory()->paid()->for($subscription)->create([
        'period_start_on' => '2026-11-01',
        'period_end_on' => '2026-11-30',
        'amount_minor' => 60000,
    ]);

    return $subscription;
}

test('a move up shows its price, the credit for the unused days and what is due, and is paid on Stripe', function () {
    travelTo('2026-11-21 10:00:00');
    $tenant = tenantOnDomain();
    basicPaidForNovember($tenant);
    $advanced = SubscriptionPlan::factory()->create(['name' => 'Agency Advanced Monthly', 'tier' => PlanTier::Advanced, 'amount_minor' => 90000]);
    fakeStripe([
        'POST /v1/customers' => ['id' => 'cus_platform', 'object' => 'customer'],
        'POST /v1/checkout/sessions' => ['id' => 'cs_new', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_new'],
    ]);

    cardBillingPage($tenant)
        ->call('openPlanChange')
        ->set('change_plan_id', (string) $advanced->id)
        ->assertSee(['Credit for unused days', '−₱200.00', 'To pay now', '₱700.00'])
        ->call('changePlan')
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_new');
});

test('a move down is scheduled for the end of the paid period, and shown until then', function () {
    travelTo('2026-11-21 10:00:00');
    $tenant = tenantOnDomain();
    $subscription = basicPaidForNovember($tenant);
    $subscription->update(['tier' => PlanTier::Advanced, 'plan_name' => 'Agency Advanced Monthly']);
    $basic = SubscriptionPlan::factory()->create(['name' => 'Agency Basic Monthly', 'tier' => PlanTier::Basic]);
    fakeStripe();

    cardBillingPage($tenant)
        ->call('openPlanChange')
        ->set('change_plan_id', (string) $basic->id)
        ->assertSee('Starts on Dec 1, 2026. Until then the agency keeps Agency Advanced Monthly.')
        ->call('changePlan')
        ->assertHasNoErrors()
        ->assertSee('Moving to Agency Basic Monthly on Dec 1, 2026.');

    expect($subscription->fresh()?->scheduled_plan_id)->toBe($basic->id);
});

test('the admin keeps the current plan instead of the scheduled move', function () {
    $tenant = tenantOnDomain();
    $subscription = basicPaidForNovember($tenant);
    $subscription->update(['tier' => PlanTier::Advanced, 'scheduled_plan_id' => SubscriptionPlan::factory()->create(['name' => 'Agency Basic Monthly'])->id]);

    cardBillingPage($tenant)
        ->assertSee('Moving to Agency Basic Monthly')
        ->call('cancelScheduledChange')
        ->assertDontSee('Moving to Agency Basic Monthly');

    expect($subscription->fresh()?->scheduled_plan_id)->toBeNull();
});

test('an invoice still owed is paid on Stripe, and one already paid offers no payment', function () {
    $tenant = tenantOnDomain();
    $subscription = Subscription::factory()->for($tenant)->create();
    $owed = SubscriptionInvoice::factory()->for($subscription)->create(['status' => SubscriptionInvoiceStatus::Open]);
    $paid = SubscriptionInvoice::factory()->paid()->for($subscription)->create(['period_start_on' => now()->subMonth()]);
    fakeStripe([
        'POST /v1/customers' => ['id' => 'cus_platform', 'object' => 'customer'],
        'POST /v1/checkout/sessions' => ['id' => 'cs_new', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_new'],
    ]);

    cardBillingPage($tenant)
        ->assertSeeHtml("data-test=\"pay-invoice-{$owed->id}\"")
        ->assertDontSeeHtml("data-test=\"pay-invoice-{$paid->id}\"")
        ->call('payInvoice', $owed->id)
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_new');
});

test('another agency\'s invoice cannot be paid from this agency\'s page', function () {
    $tenant = tenantOnDomain();
    $theirs = SubscriptionInvoice::factory()->for(Subscription::factory()->for(Tenant::factory()))->create();
    fakeStripe();

    cardBillingPage($tenant)->call('payInvoice', $theirs->id)->assertNotFound();
});

test('coming back from Stripe says whether the payment went through', function (string $checkout, string $message) {
    $tenant = tenantOnDomain();

    cardBillingPage($tenant, ['checkout' => $checkout])->assertSee($message);
})->with([
    'paid' => ['paid', 'Payment received.'],
    'cancelled' => ['cancelled', 'Payment cancelled. Nothing was charged.'],
]);
