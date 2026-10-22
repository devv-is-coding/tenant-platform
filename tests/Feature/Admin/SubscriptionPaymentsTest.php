<?php

use App\Actions\Subscriptions\RecordSubscriptionPaymentAction;
use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\Role;
use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    // Payments live in the central database, so the per-tenant database
    // provisioning that TenantCreated triggers is skipped.
    Event::fake([TenantCreated::class]);
});

function paymentsPage(Tenant $tenant, User $superAdmin): Testable
{
    return Livewire::actingAs($superAdmin)->test('pages::admin.tenants.show', ['tenant' => $tenant]);
}

/**
 * @param  array<string, mixed>  $input
 */
function recordPayment(SubscriptionInvoice $invoice, array $input = []): SubscriptionPayment
{
    return app(RecordSubscriptionPaymentAction::class)->handle(User::factory()->superAdmin()->create(), $invoice, [
        'amount' => '1000.00',
        'method' => PaymentMethod::BankTransfer->value,
        'paid_at' => today()->toDateString(),
        ...$input,
    ]);
}

test('recording part of an invoice stores the payment, recomputes what is paid and records it', function () {
    travelTo('2026-10-21 09:30:00');
    $superAdmin = User::factory()->superAdmin()->create();
    $invoice = SubscriptionInvoice::factory()->create(['reference' => 'PLAT-2026-11-0001', 'issued_on' => '2026-10-10', 'amount_minor' => 250000]);
    $tenant = $invoice->tenant;

    paymentsPage($tenant, $superAdmin)
        ->call('openPaymentForm', $invoice->id)
        ->assertSet('payment_amount', '2500.00')
        ->assertSet('payment_paid_at', '2026-10-21')
        ->set('payment_amount', '1000')
        ->set('payment_method', PaymentMethod::Cash->value)
        ->set('payment_paid_at', '2026-10-20')
        ->call('recordPayment')
        ->assertHasNoErrors()
        ->assertSet('paying_invoice_id', null)
        ->assertDispatched('toast-show');

    paymentsPage($tenant, $superAdmin)
        ->assertSeeInOrder(['Balance owed', '₱1,500.00', 'Payments', 'PLATPAY-2026-11-0001-1', 'PLAT-2026-11-0001', 'Cash', '₱1,000.00', 'Oct 20, 2026', e($superAdmin->full_name)], escape: false);

    $payment = SubscriptionPayment::sole();
    expect($payment->subscription_invoice_id)->toBe($invoice->id)
        ->and($payment->tenant_id)->toBe($tenant->id)
        ->and($payment->reference)->toBe('PLATPAY-2026-11-0001-1')
        ->and($payment->amount_minor)->toBe(100000)
        ->and($payment->currency)->toBe('PHP')
        ->and($payment->method)->toBe(PaymentMethod::Cash)
        ->and($payment->status)->toBe(PaymentRecordStatus::Succeeded)
        ->and($payment->paid_at?->toDateTimeString())->toBe('2026-10-20 00:00:00')
        ->and($payment->recorded_by)->toBe($superAdmin->id)
        ->and($payment->idempotency_key)->toBeNull();

    $invoice->refresh();
    expect($invoice->amount_paid_minor)->toBe(100000)
        ->and($invoice->status)->toBe(SubscriptionInvoiceStatus::PartiallyPaid);

    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::SubscriptionPaymentRecorded)
        ->and($event->tenant_id)->toBe($tenant->id)
        ->and($event->actor_id)->toBe($superAdmin->id)
        ->and($event->subject_type)->toBe('subscription_payment')
        ->and($event->subject_id)->toBe($payment->id)
        ->and($event->changes)->toBe([
            'amount_paid_minor' => ['old' => 0, 'new' => 100000],
            'status' => ['old' => SubscriptionInvoiceStatus::Open->value, 'new' => SubscriptionInvoiceStatus::PartiallyPaid->value],
        ]);
});

test('a deposit then the balance settles the invoice in full', function () {
    $invoice = SubscriptionInvoice::factory()->create(['reference' => 'PLAT-2026-11-0042', 'amount_minor' => 59900]);

    recordPayment($invoice, ['amount' => '400.00', 'method' => PaymentMethod::Cash->value]);
    expect($invoice->refresh()->status)->toBe(SubscriptionInvoiceStatus::PartiallyPaid)
        ->and($invoice->outstandingMinor())->toBe(19900);

    recordPayment($invoice, ['amount' => '199']);
    expect($invoice->refresh()->amount_paid_minor)->toBe(59900)
        ->and($invoice->outstandingMinor())->toBe(0)
        ->and($invoice->status)->toBe(SubscriptionInvoiceStatus::Paid)
        ->and($invoice->payments()->orderBy('id')->pluck('reference')->all())->toBe(['PLATPAY-2026-11-0042-1', 'PLATPAY-2026-11-0042-2']);
});

test('amount paid always equals the sum of the invoice\'s counted payments', function (int $seed) {
    $randomizer = new Randomizer(new Mt19937($seed));
    $invoice = SubscriptionInvoice::factory()->create(['amount_minor' => $randomizer->getInt(100, 5000000)]);
    // A declined gateway attempt is on the ledger but paid nothing.
    SubscriptionPayment::factory()->for($invoice, 'invoice')->failed()->create(['amount_minor' => $invoice->amount_minor]);
    // A payment already on the ledger that the stored counter does not yet
    // reflect: incrementing the counter would never pick it up.
    $recordedMinor = $randomizer->getInt(1, intdiv($invoice->amount_minor, 2));
    SubscriptionPayment::factory()->for($invoice, 'invoice')->create(['amount_minor' => $recordedMinor]);

    while ($recordedMinor < $invoice->amount_minor) {
        $amountMinor = $randomizer->getInt(1, $invoice->amount_minor - $recordedMinor);
        recordPayment($invoice, ['amount' => sprintf('%d.%02d', intdiv($amountMinor, 100), $amountMinor % 100)]);
        $recordedMinor += $amountMinor;

        $invoice->refresh();
        expect($invoice->amount_paid_minor)->toBe($recordedMinor)
            ->and($invoice->status)->toBe($recordedMinor === $invoice->amount_minor ? SubscriptionInvoiceStatus::Paid : SubscriptionInvoiceStatus::PartiallyPaid);
    }
})->with([7, 42, 1999, 3001, 271828]);

test('a cent float arithmetic corrupts is stored exactly and renders exactly', function () {
    $invoice = SubscriptionInvoice::factory()->create(['amount_minor' => 250000]);

    recordPayment($invoice, ['amount' => '30.01']);

    expect(SubscriptionPayment::sole()->amount_minor)->toBe(3001)
        ->and($invoice->refresh()->amount_paid_minor)->toBe(3001);
    paymentsPage($invoice->tenant, User::factory()->superAdmin()->create())
        ->assertSee('₱30.01')
        ->assertSee('₱2,469.99')
        ->assertDontSee('30.0099');
});

test('settling the last overdue invoice moves a past-due subscription back to active, and only that', function () {
    travelTo('2026-10-21 09:30:00');
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended, 'status_reason' => 'Unpaid platform fees.']);
    $subscription = Subscription::factory()->for($tenant)->pastDue()->create();
    $invoice = SubscriptionInvoice::factory()->for($subscription)->overdue()->create();
    $superAdmin = User::factory()->superAdmin()->create();

    app(RecordSubscriptionPaymentAction::class)->handle($superAdmin, $invoice, [
        'amount' => '2500',
        'method' => PaymentMethod::BankTransfer->value,
        'paid_at' => '2026-10-21',
    ]);

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($tenant->refresh()->status)->toBe(TenantStatus::Suspended);

    $event = PlatformAuditEvent::query()->where('action', AuditAction::SubscriptionPastDueCleared)->sole();
    expect($event->actor_id)->toBe($superAdmin->id)
        ->and($event->subject_type)->toBe('subscription')
        ->and($event->subject_id)->toBe($subscription->id)
        ->and($event->changes)->toBe(['status' => ['old' => SubscriptionStatus::PastDue->value, 'new' => SubscriptionStatus::Active->value]]);
});

test('a past-due subscription stays past due while another invoice is still overdue', function () {
    travelTo('2026-10-21 09:30:00');
    $subscription = Subscription::factory()->pastDue()->create();
    $invoice = SubscriptionInvoice::factory()->for($subscription)->overdue()->create(['amount_minor' => 250000]);
    SubscriptionInvoice::factory()->for($subscription)->overdue(40)->create(['period_start_on' => '2026-08-21']);

    recordPayment($invoice, ['amount' => '2500']);

    expect($invoice->refresh()->status)->toBe(SubscriptionInvoiceStatus::Paid)
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and(PlatformAuditEvent::query()->where('action', AuditAction::SubscriptionPastDueCleared)->exists())->toBeFalse();
});

test('a part payment leaves a past-due subscription past due', function () {
    travelTo('2026-10-21 09:30:00');
    $subscription = Subscription::factory()->pastDue()->create();
    $invoice = SubscriptionInvoice::factory()->for($subscription)->overdue()->create(['amount_minor' => 250000]);

    recordPayment($invoice, ['amount' => '2499.99']);

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($invoice->refresh()->status)->toBe(SubscriptionInvoiceStatus::PartiallyPaid);
});

test('a payment the invoice cannot take is refused and nothing is written', function (Closure $makeInvoice, string $amount, string $message) {
    /** @var SubscriptionInvoice $invoice */
    $invoice = $makeInvoice();
    $before = $invoice->only(['amount_paid_minor', 'status']);
    $paymentsBefore = SubscriptionPayment::count();

    paymentsPage($invoice->tenant, User::factory()->superAdmin()->create())
        ->call('openPaymentForm', $invoice->id)
        ->set('payment_amount', $amount)
        ->call('recordPayment')
        ->assertHasErrors(['payment_amount' => $message]);

    expect(SubscriptionPayment::count())->toBe($paymentsBefore)
        ->and($invoice->refresh()->only(['amount_paid_minor', 'status']))->toBe($before)
        ->and(PlatformAuditEvent::count())->toBe(0);
})->with([
    'more than is owed' => [
        fn () => SubscriptionPayment::factory()
            ->for(SubscriptionInvoice::factory()->partiallyPaid(100000)->state(['amount_minor' => 250000]), 'invoice')
            ->create(['amount_minor' => 100000])
            ->invoice,
        '1500.01',
        'The amount is more than the ₱1,500.00 still owed.',
    ],
    'already paid' => [fn () => SubscriptionInvoice::factory()->paid()->create(['reference' => 'PLAT-2026-10-0007']), '1', 'Invoice PLAT-2026-10-0007 is paid and takes no more payments.'],
    'void' => [fn () => SubscriptionInvoice::factory()->void()->create(['reference' => 'PLAT-2026-10-0008']), '1', 'Invoice PLAT-2026-10-0008 is void and takes no more payments.'],
]);

test('an invalid payment is rejected on the form\'s own fields and changes nothing', function (array $input, string $field, string $message) {
    travelTo('2026-10-21 09:30:00');
    $invoice = SubscriptionInvoice::factory()->create(['issued_on' => '2026-10-10']);

    paymentsPage($invoice->tenant, User::factory()->superAdmin()->create())
        ->call('openPaymentForm', $invoice->id)
        ->set($input)
        ->call('recordPayment')
        ->assertHasErrors([$field => $message])
        ->assertHasNoErrors(['amount']);

    expect(SubscriptionPayment::count())->toBe(0)
        ->and($invoice->refresh()->amount_paid_minor)->toBe(0);
})->with([
    'zero' => [['payment_amount' => '0.00'], 'payment_amount', 'The amount must be more than zero.'],
    'three decimal places' => [['payment_amount' => '1.234'], 'payment_amount', 'Enter the amount as a number with at most two decimal places, without commas.'],
    'thousands separator' => [['payment_amount' => '1,000'], 'payment_amount', 'Enter the amount as a number with at most two decimal places, without commas.'],
    'a gateway method' => [['payment_method' => PaymentMethod::Stripe->value], 'payment_method', 'The selected method is invalid.'],
    'in the future' => [['payment_paid_at' => '2026-10-22'], 'payment_paid_at', 'The payment date cannot be in the future.'],
    'before the invoice was issued' => [['payment_paid_at' => '2026-10-09'], 'payment_paid_at', 'The payment date cannot be before the invoice was issued.'],
]);

test('another agency\'s invoice cannot be opened for payment from this agency\'s page', function () {
    $tenant = Tenant::factory()->create();
    $otherInvoice = SubscriptionInvoice::factory()->create();

    paymentsPage($tenant, User::factory()->superAdmin()->create())
        ->call('openPaymentForm', $otherInvoice->id)
        ->assertNotFound();

    expect(SubscriptionPayment::count())->toBe(0);
});

test('recording a payment is refused once the user is no longer a super admin', function () {
    $invoice = SubscriptionInvoice::factory()->create();
    $page = paymentsPage($invoice->tenant, User::factory()->superAdmin()->create())->call('openPaymentForm', $invoice->id);
    actingAs(User::factory()->role(Role::Admin)->for($invoice->tenant)->create());

    $page->call('recordPayment')->assertForbidden();

    expect(SubscriptionPayment::count())->toBe(0);
});

test('an invoice still owed offers a payment button', function (Closure $makeInvoice) {
    /** @var SubscriptionInvoice $invoice */
    $invoice = $makeInvoice();

    paymentsPage($invoice->tenant, User::factory()->superAdmin()->create())
        ->assertSeeHtml("openPaymentForm({$invoice->id})");
})->with([
    'open' => [fn () => SubscriptionInvoice::factory()->create()],
    'partly paid' => [fn () => SubscriptionInvoice::factory()->partiallyPaid()->create()],
]);

test('a settled or withdrawn invoice offers no payment button', function (Closure $makeInvoice) {
    /** @var SubscriptionInvoice $invoice */
    $invoice = $makeInvoice();

    paymentsPage($invoice->tenant, User::factory()->superAdmin()->create())
        ->assertDontSeeHtml("openPaymentForm({$invoice->id})");
})->with([
    'paid' => [fn () => SubscriptionInvoice::factory()->paid()->create()],
    'void' => [fn () => SubscriptionInvoice::factory()->void()->create()],
]);

test('the balance owed counts only this agency\'s unsettled invoices', function () {
    $subscription = Subscription::factory()->create();
    $tenant = $subscription->tenant;
    $invoices = SubscriptionInvoice::factory()->for($subscription);
    $invoices->create(['amount_minor' => 250000, 'period_start_on' => '2026-07-01']);
    $invoices->partiallyPaid(100001)->create(['amount_minor' => 250000, 'period_start_on' => '2026-08-01']);
    $invoices->paid()->create(['period_start_on' => '2026-09-01']);
    $invoices->void()->create(['period_start_on' => '2026-10-01']);
    SubscriptionInvoice::factory()->create(['amount_minor' => 900000]);

    paymentsPage($tenant, User::factory()->superAdmin()->create())
        ->assertSeeInOrder(['Balance owed', '₱3,999.99']);
});

test('the payments list shows only this agency\'s payments, most recently paid first', function () {
    $invoice = SubscriptionInvoice::factory()->create();
    $older = SubscriptionPayment::factory()->for($invoice, 'invoice')->create(['reference' => 'PLATPAY-OLDER', 'paid_at' => now()->subDays(3)]);
    $newer = SubscriptionPayment::factory()->for($invoice, 'invoice')->create(['reference' => 'PLATPAY-NEWER', 'paid_at' => now()->subDay()]);
    SubscriptionPayment::factory()->create(['reference' => 'PLATPAY-ANOTHER-AGENCY']);

    $page = paymentsPage($invoice->tenant, User::factory()->superAdmin()->create());

    expect($page->instance()->payments->modelKeys())->toBe([$newer->id, $older->id]);
    $page->assertSeeInOrder(['PLATPAY-NEWER', 'PLATPAY-OLDER'])
        ->assertDontSee('PLATPAY-ANOTHER-AGENCY');
});
