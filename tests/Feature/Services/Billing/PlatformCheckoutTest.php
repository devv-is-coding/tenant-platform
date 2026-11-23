<?php

use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\SubscriptionInvoiceStatus;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\PlatformCheckout;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\travelTo;

/**
 * Put the agency on a ₱1,000.00 Basic plan for November 2026, with that
 * period's invoice paid the given amount.
 */
function onBasicForNovember(Tenant $tenant, int $paidMinor, PlanTier $tier = PlanTier::Basic): Subscription
{
    $subscription = Subscription::factory()->for($tenant)->create([
        'plan_name' => 'Agency Basic Monthly',
        'tier' => $tier,
        'amount_minor' => 100000,
        'starts_on' => '2026-11-01',
        'current_period_start_on' => '2026-11-01',
        'current_period_end_on' => '2026-11-30',
        'next_billing_on' => '2026-12-01',
    ]);
    SubscriptionInvoice::factory()->for($subscription)->create([
        'period_start_on' => '2026-11-01',
        'period_end_on' => '2026-11-30',
        'issued_on' => '2026-11-01',
        'due_on' => '2026-11-11',
        'amount_minor' => 100000,
        'amount_paid_minor' => $paidMinor,
        'status' => SubscriptionInvoiceStatus::fromAmounts(100000, $paidMinor),
    ]);

    return $subscription;
}

/**
 * Answer Stripe's customer and Checkout session calls.
 *
 * @return ArrayObject<int, array{0: string, 1: string, 2: array<string, mixed>, 3: string|null}>
 */
function fakeCheckoutStripe(): ArrayObject
{
    return fakeStripe([
        'POST /v1/customers' => ['id' => 'cus_platform', 'object' => 'customer'],
        'POST /v1/checkout/sessions' => ['id' => 'cs_new', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_new'],
    ]);
}

test('an upgrade costs the new plan less what the unused days of the current one were paid', function (int $paidMinor, int $creditMinor) {
    travelTo('2026-11-21 10:00:00');
    $tenant = tenantOnDomain();
    onBasicForNovember($tenant, $paidMinor);
    $advanced = SubscriptionPlan::factory()->create(['tier' => PlanTier::Advanced, 'amount_minor' => 200000]);

    $quote = app(PlatformCheckout::class)->quote($tenant, $advanced, today());

    expect([$quote['credit']->minor, $quote['amount']->minor])->toBe([$creditMinor, 200000 - $creditMinor]);
})->with([
    'paid in full, 10 of 30 days unused' => [100000, 33333],
    'half paid' => [50000, 16666],
    'not paid' => [0, 0],
]);

test('a move down, or across at the same tier, is not sold: it waits for the paid period to end', function (PlanTier $current, PlanTier $chosen) {
    travelTo('2026-11-21 10:00:00');
    $tenant = tenantOnDomain();
    onBasicForNovember($tenant, 100000, $current);
    $plan = SubscriptionPlan::factory()->create(['name' => 'Agency Basic Yearly', 'tier' => $chosen]);

    expect(fn (): array => app(PlatformCheckout::class)->quote($tenant, $plan, today()))
        ->toThrow(ValidationException::class, 'Agency Basic Yearly is not a move up, so it starts when the paid period ends.');
})->with([
    'down' => [PlanTier::Advanced, PlanTier::Basic],
    'across' => [PlanTier::Basic, PlanTier::Basic],
]);

test('buying a plan opens a Stripe Checkout payment for its price, as the agency, named for the webhook', function () {
    travelTo('2026-11-21 10:00:00');
    $tenant = tenantOnDomain();
    $tenant->update(['name' => 'Dumaguete Travel']);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create(['email' => 'admin@dumaguete.test']);
    $plan = SubscriptionPlan::factory()->create(['name' => 'Agency Advanced Monthly', 'tier' => PlanTier::Advanced, 'amount_minor' => 200000]);
    $sent = fakeCheckoutStripe();

    $url = withinTenant($tenant, fn (): string => app(PlatformCheckout::class)->planUrl($tenant, $plan, $admin));

    $calls = collect($sent->getArrayCopy())->keyBy(0);
    expect($url)->toBe('https://checkout.stripe.com/c/pay/cs_new')
        ->and($tenant->fresh()?->stripe_customer_id)->toBe('cus_platform')
        ->and($calls['POST /v1/customers'][2])->toMatchArray(['name' => 'Dumaguete Travel', 'email' => 'admin@dumaguete.test'])
        ->and($calls['POST /v1/checkout/sessions'][2])->toMatchArray(['mode' => 'payment', 'customer' => 'cus_platform', 'client_reference_id' => $tenant->id])
        ->and($calls['POST /v1/checkout/sessions'][2]['line_items'])->toBe([[
            'quantity' => 1,
            'price_data' => ['currency' => 'php', 'unit_amount' => 200000, 'product_data' => ['name' => 'Agency Advanced Monthly']],
        ]])
        ->and($calls['POST /v1/checkout/sessions'][2]['metadata'])->toBe([
            'purpose' => 'platform_plan',
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => (string) $plan->id,
            'amount_minor' => '200000',
            'credit_minor' => '0',
            'admin_id' => (string) $admin->id,
        ])
        ->and($calls['POST /v1/checkout/sessions'][3])->toBe("checkout:plan:{$tenant->id}:{$plan->id}:2026-11-21:200000");
});

test('a withdrawn plan cannot be bought', function () {
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $plan = tap(SubscriptionPlan::factory()->create(['tier' => PlanTier::Advanced]))->delete();
    fakeStripe();

    expect(fn (): string => withinTenant($tenant, fn (): string => app(PlatformCheckout::class)->planUrl($tenant, $plan, $admin)))
        ->toThrow(ValidationException::class, 'That plan is no longer offered. Choose another.');
});

test('an invoice is paid by card for what it still owes', function () {
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $invoice = SubscriptionInvoice::factory()->partiallyPaid(40000)->for(Subscription::factory()->for($tenant))->create(['reference' => 'PLAT-2026-11-0001', 'amount_minor' => 100000]);
    $sent = fakeCheckoutStripe();

    withinTenant($tenant, fn (): string => app(PlatformCheckout::class)->invoiceUrl($invoice, $admin));

    $session = collect($sent->getArrayCopy())->keyBy(0)['POST /v1/checkout/sessions'];
    expect($session[2]['line_items'][0]['price_data'])->toMatchArray(['unit_amount' => 60000, 'product_data' => ['name' => 'Invoice PLAT-2026-11-0001']])
        ->and($session[2]['metadata'])->toBe([
            'purpose' => 'platform_invoice',
            'tenant_id' => $tenant->id,
            'subscription_invoice_id' => (string) $invoice->id,
            'admin_id' => (string) $admin->id,
        ])
        ->and($session[3])->toBe("checkout:invoice:{$invoice->id}:40000");
});

test('an invoice with nothing left to pay is not sold', function (string $state) {
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $invoice = SubscriptionInvoice::factory()->{$state}()->for(Subscription::factory()->for($tenant))->create(['reference' => 'PLAT-2026-11-0001']);
    fakeStripe();

    expect(fn (): string => withinTenant($tenant, fn (): string => app(PlatformCheckout::class)->invoiceUrl($invoice, $admin)))
        ->toThrow(ValidationException::class, 'PLAT-2026-11-0001 has nothing left to pay.');
})->with(['paid', 'void']);

test('stripe is never called inside a database transaction', function () {
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $plan = SubscriptionPlan::factory()->create(['tier' => PlanTier::Advanced]);
    fakeStripe();

    expect(fn () => withinTenant($tenant, fn () => DB::transaction(fn (): string => app(PlatformCheckout::class)->planUrl($tenant, $plan, $admin))))
        ->toThrow(LogicException::class, 'Stripe is never called inside a database transaction');
});
