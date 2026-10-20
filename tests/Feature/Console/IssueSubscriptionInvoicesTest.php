<?php

use App\Enums\AuditAction;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\artisan;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    // Billing is entirely central, so no tenant database is provisioned.
    Event::fake([TenantCreated::class]);
});

/**
 * A live monthly subscription whose first period starts on the given day.
 */
function monthlySubscriptionFrom(string $startsOn, array $attributes = []): Subscription
{
    return Subscription::factory()->for(Tenant::factory()->create(['name' => 'Dumaguete City Travel & Tours']))->create([
        'interval' => BillingInterval::Monthly,
        'starts_on' => $startsOn,
        'current_period_start_on' => $startsOn,
        'current_period_end_on' => now()->parse($startsOn)->addMonthNoOverflow()->subDay(),
        'next_billing_on' => now()->parse($startsOn)->addMonthNoOverflow(),
        ...$attributes,
    ]);
}

test('the first run issues the current period\'s invoice and records it', function () {
    travelTo('2026-10-10 06:00:00');
    $subscription = monthlySubscriptionFrom('2026-10-10');

    artisan('subscriptions:issue-invoices')
        ->expectsOutputToContain('Issued 1 invoice(s); marked 0 subscription(s) past due.')
        ->assertSuccessful();

    $invoice = SubscriptionInvoice::sole();
    expect($invoice->subscription_id)->toBe($subscription->id)
        ->and($invoice->tenant_id)->toBe($subscription->tenant_id)
        ->and($invoice->reference)->toBe('PLAT-2026-11-0001')
        ->and($invoice->period_start_on->toDateString())->toBe('2026-10-10')
        ->and($invoice->period_end_on->toDateString())->toBe('2026-11-09')
        ->and($invoice->issued_on->toDateString())->toBe('2026-10-10')
        ->and($invoice->due_on->toDateString())->toBe('2026-10-20')
        ->and($invoice->amount_minor)->toBe(250000)
        ->and($invoice->currency)->toBe('PHP')
        ->and($invoice->amount_paid_minor)->toBe(0)
        ->and($invoice->fresh()?->status)->toBe(SubscriptionInvoiceStatus::Open);

    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::SubscriptionInvoiceIssued)
        ->and($event->actor_id)->toBeNull()
        ->and($event->tenant_id)->toBe($subscription->tenant_id)
        ->and($event->tenant_label)->toBe('Dumaguete City Travel & Tours')
        ->and($event->subject_type)->toBe('subscription_invoice')
        ->and($event->subject_id)->toBe($invoice->id);
});

test('running twice on the same day issues the invoice once', function () {
    travelTo('2026-10-10 06:00:00');
    monthlySubscriptionFrom('2026-10-10');

    artisan('subscriptions:issue-invoices')->assertSuccessful();
    artisan('subscriptions:issue-invoices')
        ->expectsOutputToContain('Issued 0 invoice(s)')
        ->assertSuccessful();

    expect(SubscriptionInvoice::count())->toBe(1)
        ->and(PlatformAuditEvent::count())->toBe(1);
});

test('on the next billing date the period rolls forward and its invoice is issued', function () {
    $subscription = monthlySubscriptionFrom('2026-10-10');
    travelTo('2026-10-10 06:00:00');
    artisan('subscriptions:issue-invoices')->assertSuccessful();

    travelTo('2026-11-10 06:00:00');
    artisan('subscriptions:issue-invoices')->assertSuccessful();

    $subscription->refresh();
    expect($subscription->current_period_start_on->toDateString())->toBe('2026-11-10')
        ->and($subscription->current_period_end_on->toDateString())->toBe('2026-12-09')
        ->and($subscription->next_billing_on?->toDateString())->toBe('2026-12-10')
        ->and($subscription->invoices()->orderBy('period_start_on')->pluck('reference')->all())
        ->toBe(['PLAT-2026-11-0001', 'PLAT-2026-12-0001']);
});

test('a missed run catches up one invoice per period', function () {
    $subscription = monthlySubscriptionFrom('2026-10-10');

    travelTo('2026-12-15 06:00:00');
    artisan('subscriptions:issue-invoices')
        ->expectsOutputToContain('Issued 3 invoice(s)')
        ->assertSuccessful();

    expect($subscription->invoices()->orderBy('period_start_on')->get()->map(fn (SubscriptionInvoice $invoice): string => $invoice->period_start_on->toDateString())->all())
        ->toBe(['2026-10-10', '2026-11-10', '2026-12-10'])
        ->and($subscription->fresh()?->next_billing_on?->toDateString())->toBe('2027-01-10');
});

test('a period that already has its invoice issues nothing new', function () {
    travelTo('2026-10-15 06:00:00');
    $subscription = monthlySubscriptionFrom('2026-10-10');
    SubscriptionInvoice::factory()->for($subscription)->create(['period_start_on' => '2026-10-10', 'tenant_id' => $subscription->tenant_id]);

    artisan('subscriptions:issue-invoices')
        ->expectsOutputToContain('Issued 0 invoice(s)')
        ->assertSuccessful();

    expect(SubscriptionInvoice::count())->toBe(1)
        ->and(PlatformAuditEvent::count())->toBe(0);
});

test('ended subscriptions are not billed', function (SubscriptionStatus $status) {
    travelTo('2026-10-10 06:00:00');
    monthlySubscriptionFrom('2026-10-10', ['status' => $status, 'active_for_tenant_id' => null]);

    artisan('subscriptions:issue-invoices')->assertSuccessful();

    expect(SubscriptionInvoice::count())->toBe(0);
})->with([
    'cancelled' => SubscriptionStatus::Cancelled,
    'expired' => SubscriptionStatus::Expired,
]);

test('a period inside a free trial is not invoiced, and the first paid period is', function () {
    $subscription = monthlySubscriptionFrom('2026-10-10', ['status' => SubscriptionStatus::Trialing, 'trial_ends_on' => '2026-11-10']);

    travelTo('2026-10-10 06:00:00');
    artisan('subscriptions:issue-invoices')->assertSuccessful();
    expect(SubscriptionInvoice::count())->toBe(0);

    travelTo('2026-11-10 06:00:00');
    artisan('subscriptions:issue-invoices')->assertSuccessful();
    expect($subscription->invoices()->sole()->period_start_on->toDateString())->toBe('2026-11-10');
});

test('an overdue invoice marks the subscription past due and leaves the agency active', function () {
    travelTo('2026-10-10 06:00:00');
    $subscription = monthlySubscriptionFrom('2026-10-10');
    artisan('subscriptions:issue-invoices')->assertSuccessful();

    travelTo('2026-10-21 06:00:00');
    artisan('subscriptions:issue-invoices')
        ->expectsOutputToContain('marked 1 subscription(s) past due.')
        ->assertSuccessful();

    expect($subscription->fresh()?->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->tenant->fresh()?->status)->toBe(TenantStatus::Active);

    $event = PlatformAuditEvent::query()->where('action', AuditAction::SubscriptionMarkedPastDue)->sole();
    expect($event->actor_id)->toBeNull()
        ->and($event->subject_type)->toBe('subscription')
        ->and($event->subject_id)->toBe($subscription->id)
        ->and($event->changes)->toBe(['status' => ['old' => 2, 'new' => 3]]);
});

test('a subscription stays active while its invoices are paid or not yet due', function (string $today, Closure $settle) {
    travelTo('2026-10-10 06:00:00');
    $subscription = monthlySubscriptionFrom('2026-10-10');
    artisan('subscriptions:issue-invoices')->assertSuccessful();
    $settle(SubscriptionInvoice::sole());

    travelTo($today);
    artisan('subscriptions:issue-invoices')->assertSuccessful();

    expect($subscription->fresh()?->status)->toBe(SubscriptionStatus::Active);
})->with([
    'due today' => ['2026-10-20 06:00:00', fn () => null],
    'paid, past its due date' => ['2026-10-25 06:00:00', fn (SubscriptionInvoice $invoice) => $invoice->update(['status' => SubscriptionInvoiceStatus::Paid, 'amount_paid_minor' => 250000])],
    'void, past its due date' => ['2026-10-25 06:00:00', fn (SubscriptionInvoice $invoice) => $invoice->update(['status' => SubscriptionInvoiceStatus::Void])],
]);
