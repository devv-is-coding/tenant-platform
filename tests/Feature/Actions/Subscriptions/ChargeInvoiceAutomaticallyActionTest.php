<?php

use App\Actions\Subscriptions\ChargeInvoiceAutomaticallyAction;
use App\Enums\PaymentMethod;
use App\Enums\PlatformNoticeType;
use App\Enums\SubscriptionInvoiceStatus;
use App\Models\PlatformNotice;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;
use Stripe\Exception\CardException;

use function Pest\Laravel\travelTo;

beforeEach(function () {
    // Platform billing is central, so no tenant database is provisioned.
    Event::fake([TenantCreated::class]);
});

/**
 * An agency with a saved Visa ending 4242, auto-pay on since the given moment.
 */
function agencyOnAutoPay(?string $since = '2026-11-01 00:00:00'): Tenant
{
    $tenant = Tenant::factory()->create();
    $tenant->forceFill([
        'stripe_customer_id' => 'cus_1',
        'stripe_payment_method_id' => 'pm_1',
        'card_brand' => 'visa',
        'card_last4' => '4242',
        'auto_pay_since' => $since,
    ])->save();

    return $tenant;
}

/**
 * The agency's ₱600.00 invoice for December 2026, issued on the 1st, due the 11th.
 */
function invoiceToAutoPay(Tenant $tenant): SubscriptionInvoice
{
    return SubscriptionInvoice::factory()->for(Subscription::factory()->for($tenant))->create([
        'reference' => 'PLAT-2026-12-0001',
        'period_start_on' => '2026-12-01',
        'period_end_on' => '2026-12-31',
        'issued_on' => '2026-12-01',
        'due_on' => '2026-12-11',
        'amount_minor' => 60000,
    ]);
}

/**
 * Stripe's answer for a declined card.
 */
function declinedCard(): Closure
{
    return fn (): never => throw CardException::factory('Your card was declined.', 402, null, null, null, 'card_declined', 'generic_decline');
}

test('an invoice issued with auto-pay on is charged to the saved card, and paid', function () {
    travelTo('2026-12-01 06:00:00');
    $invoice = invoiceToAutoPay(agencyOnAutoPay());
    $sent = fakeStripe(['POST /v1/payment_intents' => ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded']]);

    $outcome = app(ChargeInvoiceAutomaticallyAction::class)->handle($invoice, today());

    expect($outcome)->toBe('paid')
        ->and([$invoice->fresh()?->status, $invoice->fresh()?->amount_paid_minor])->toBe([SubscriptionInvoiceStatus::Paid, 60000])
        ->and(SubscriptionPayment::query()->sole()->only(['method', 'idempotency_key', 'stripe_payment_intent_id']))
        ->toBe(['method' => PaymentMethod::Stripe, 'idempotency_key' => 'stripe:payment_intent:pi_1', 'stripe_payment_intent_id' => 'pi_1'])
        ->and($sent[0][2])->toMatchArray([
            'amount' => 60000, 'currency' => 'php', 'customer' => 'cus_1', 'payment_method' => 'pm_1', 'off_session' => true, 'confirm' => true,
        ])
        ->and($sent[0][2]['metadata'])->toMatchArray(['purpose' => 'platform_autopay', 'subscription_invoice_id' => (string) $invoice->id])
        ->and($sent[0][3])->toBe("autopay:{$invoice->id}:1");
});

test('a declined charge is tried once more three days later, then left to be paid by hand, the admin told each time', function () {
    travelTo('2026-12-01 06:00:00');
    $invoice = invoiceToAutoPay(agencyOnAutoPay());
    $sent = fakeStripe(['POST /v1/payment_intents' => declinedCard()]);
    $outcomes = [];

    foreach (['2026-12-01', '2026-12-02', '2026-12-04', '2026-12-08'] as $day) {
        travelTo("{$day} 06:00:00");
        $outcomes[] = app(ChargeInvoiceAutomaticallyAction::class)->handle($invoice->fresh() ?? $invoice, today());
    }

    expect($outcomes)->toBe(['failed', 'skipped', 'failed', 'skipped'])
        ->and(collect($sent->getArrayCopy())->pluck(3)->all())->toBe(["autopay:{$invoice->id}:1", "autopay:{$invoice->id}:2"])
        ->and([$invoice->fresh()?->auto_charge_attempts, $invoice->fresh()?->status])->toBe([2, SubscriptionInvoiceStatus::Open])
        ->and(PlatformNotice::query()->orderBy('id')->get()->map(fn (PlatformNotice $notice): array => [$notice->notice_type, $notice->message])->all())
        ->toBe([
            [PlatformNoticeType::AutoPayFailed, 'The automatic payment of ₱600.00 for PLAT-2026-12-0001 failed: Your card was declined. It is tried once more on Dec 4, 2026, or you can pay it now.'],
            [PlatformNoticeType::AutoPayFailed, 'The automatic payment of ₱600.00 for PLAT-2026-12-0001 failed again: Your card was declined. Pay it by hand before Dec 11, 2026.'],
        ]);
});

test('nothing is charged with auto-pay off, or for an invoice issued before the day it was turned on', function (?string $since) {
    travelTo('2026-12-03 06:00:00');
    $invoice = invoiceToAutoPay(agencyOnAutoPay($since));
    fakeStripe();

    $outcome = app(ChargeInvoiceAutomaticallyAction::class)->handle($invoice, today());

    expect([$outcome, $invoice->fresh()?->auto_charge_attempts])->toBe(['skipped', 0]);
})->with([
    'auto-pay off' => [null],
    'turned on the day after the invoice was issued' => ['2026-12-02 09:00:00'],
]);

test('an invoice issued the day auto-pay was turned on is charged', function () {
    travelTo('2026-12-01 09:30:00');
    $invoice = invoiceToAutoPay(agencyOnAutoPay('2026-12-01 09:00:00'));
    fakeStripe(['POST /v1/payment_intents' => ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded']]);

    expect(app(ChargeInvoiceAutomaticallyAction::class)->handle($invoice, today()))->toBe('paid');
});

test('turning auto-pay off after a failed charge stops the retry', function () {
    travelTo('2026-12-01 06:00:00');
    $tenant = agencyOnAutoPay();
    $invoice = invoiceToAutoPay($tenant);
    $sent = fakeStripe(['POST /v1/payment_intents' => declinedCard()]);
    app(ChargeInvoiceAutomaticallyAction::class)->handle($invoice, today());
    $tenant->forceFill(['auto_pay_since' => null])->save();
    travelTo('2026-12-04 06:00:00');

    $outcome = app(ChargeInvoiceAutomaticallyAction::class)->handle($invoice->fresh() ?? $invoice, today());

    expect([$outcome, $sent->count()])->toBe(['skipped', 1]);
});

test('a card that asks the cardholder to confirm, which cannot happen unattended, counts as a failed charge', function () {
    travelTo('2026-12-01 06:00:00');
    $invoice = invoiceToAutoPay(agencyOnAutoPay());
    fakeStripe(['POST /v1/payment_intents' => ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'requires_action']]);

    $outcome = app(ChargeInvoiceAutomaticallyAction::class)->handle($invoice, today());

    expect([$outcome, SubscriptionPayment::count()])->toBe(['failed', 0])
        ->and(PlatformNotice::query()->sole()->message)->toContain('failed: the card asks the cardholder to confirm the payment.');
});

test('a charge Stripe is still processing is left for its webhook to record', function () {
    travelTo('2026-12-01 06:00:00');
    $invoice = invoiceToAutoPay(agencyOnAutoPay());
    fakeStripe(['POST /v1/payment_intents' => ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'processing']]);

    $outcome = app(ChargeInvoiceAutomaticallyAction::class)->handle($invoice, today());

    expect([$outcome, SubscriptionPayment::count(), PlatformNotice::count()])->toBe(['pending', 0, 0]);
});
