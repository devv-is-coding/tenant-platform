<?php

use App\Enums\AuditAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\PaymentMethod;
use App\Enums\RefundStatus;
use App\Enums\RefundType;
use App\Models\AuditEvent;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Tenant;
use App\ValueObjects\Money;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\call;

/**
 * Post the event to the webhook as Stripe would, signed with the secret.
 *
 * @param  array<string, mixed>  $event
 */
function deliverStripeEvent(array $event, ?string $secret = 'whsec_test', string $host = 'localhost'): TestResponse
{
    $payload = (string) json_encode($event);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", (string) $secret);

    return call('POST', "http://{$host}/api/stripe/webhook", server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], content: $payload);
}

/**
 * Stripe's invoice.paid event for a balance invoice of the agency's booking.
 *
 * @return array<string, mixed>
 */
function invoicePaidEvent(Tenant $tenant, string $reference, int $amountPaidMinor, string $eventId = 'evt_1'): array
{
    return [
        'id' => $eventId,
        'type' => 'invoice.paid',
        'created' => 1796342400,
        'data' => ['object' => [
            'id' => 'in_1',
            'object' => 'invoice',
            'amount_paid' => $amountPaidMinor,
            'currency' => 'php',
            'status_transitions' => ['paid_at' => 1796342400],
            'metadata' => ['tenant_id' => $tenant->id, 'booking_reference' => $reference],
        ]],
    ];
}

/**
 * The invoice's payment, as Stripe lists it.
 *
 * @return array<string, array<string, mixed>>
 */
function invoicePaidThrough(string $paymentIntentId): array
{
    return ['GET /v1/invoice_payments' => ['object' => 'list', 'data' => [
        ['id' => 'inpay_1', 'object' => 'invoice_payment', 'status' => 'paid', 'payment' => ['type' => 'payment_intent', 'payment_intent' => $paymentIntentId]],
    ]]];
}

beforeEach(function () {
    config(['cashier.webhook.secret' => 'whsec_test']);
});

test('a paid balance invoice is recorded once in its agency, however often Stripe delivers it', function () {
    $tenant = tenantOnDomain();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('199.00', 'PHP'), 'reference' => 'DUMA-2026-AAAAAA']));
    fakeStripe(invoicePaidThrough('pi_1'));

    deliverStripeEvent(invoicePaidEvent($tenant, 'DUMA-2026-AAAAAA', 19900))->assertOk()->assertJson(['status' => 'handled']);
    deliverStripeEvent(invoicePaidEvent($tenant, 'DUMA-2026-AAAAAA', 19900, 'evt_2'))->assertOk();

    $recorded = withinTenant($tenant, fn (): array => [Payment::query()->sole(), $booking->fresh()]);

    expect($recorded[0]->only(['amount_minor', 'idempotency_key', 'stripe_payment_intent_id']))->toBe([
        'amount_minor' => 19900,
        'idempotency_key' => 'stripe:invoice:in_1',
        'stripe_payment_intent_id' => 'pi_1',
    ])
        ->and($recorded[0]->method)->toBe(PaymentMethod::Stripe)
        ->and($recorded[1]?->payment_status)->toBe(BookingPaymentStatus::Paid);
});

test('an event without a valid signature is refused with 403 and changes nothing', function (?string $secret) {
    $tenant = tenantOnDomain();
    withinTenant($tenant, fn (): Booking => Booking::factory()->create(['reference' => 'DUMA-2026-AAAAAA']));
    fakeStripe();

    deliverStripeEvent(invoicePaidEvent($tenant, 'DUMA-2026-AAAAAA', 19900), $secret)->assertForbidden();

    expect(withinTenant($tenant, fn (): int => Payment::query()->count()))->toBe(0);
})->with([
    'signed with another secret' => 'whsec_forged',
    'unsigned' => '',
]);

test('with no webhook secret configured every event is refused, even one that looks signed', function () {
    config(['cashier.webhook.secret' => null]);
    $tenant = tenantOnDomain();

    deliverStripeEvent(invoicePaidEvent($tenant, 'DUMA-2026-AAAAAA', 19900), '')->assertForbidden();
});

test('an event the app cannot place is acknowledged and ignored, not failed', function (Closure $event) {
    $tenant = tenantOnDomain();
    withinTenant($tenant, fn (): Booking => Booking::factory()->create(['reference' => 'DUMA-2026-AAAAAA']));
    fakeStripe();

    deliverStripeEvent($event($tenant))->assertOk()->assertJson(['status' => 'ignored']);

    expect(withinTenant($tenant, fn (): int => Payment::query()->count()))->toBe(0);
})->with([
    'unknown booking' => fn (Tenant $tenant): array => invoicePaidEvent($tenant, 'DUMA-2026-ZZZZZZ', 19900),
    'unknown agency' => fn (Tenant $tenant): array => invoicePaidEvent(new Tenant(['id' => 'gone']), 'DUMA-2026-AAAAAA', 19900),
    'nothing paid' => fn (Tenant $tenant): array => invoicePaidEvent($tenant, 'DUMA-2026-AAAAAA', 0),
    'another event' => fn (Tenant $tenant): array => ['id' => 'evt_1', 'type' => 'customer.created', 'data' => ['object' => ['metadata' => ['tenant_id' => $tenant->id]]]],
]);

test('a failed card payment is noted in the agency\'s log and moves no money', function () {
    $tenant = tenantOnDomain();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['reference' => 'DUMA-2026-AAAAAA']));

    deliverStripeEvent([...invoicePaidEvent($tenant, 'DUMA-2026-AAAAAA', 0), 'type' => 'invoice.payment_failed'])->assertOk();

    expect(withinTenant($tenant, fn (): array => [
        AuditEvent::query()->where('action', AuditAction::PaymentFailed)->count(),
        $booking->fresh()?->payment_status,
    ]))->toBe([1, BookingPaymentStatus::Unpaid]);
});

test('a refund Stripe finished later is settled when its update arrives', function () {
    $tenant = tenantOnDomain();
    [$booking, $refund] = withinTenant($tenant, function (): array {
        $booking = Booking::factory()->create(['total' => Money::parse('199.00', 'PHP')]);
        $payment = Payment::factory()->viaStripe()->for($booking)->amount(Money::parse('199.00', 'PHP'))->create();
        $booking->recomputeMoney();

        return [$booking, Refund::factory()->for($booking)->for($payment)->create([
            'type' => RefundType::Partial, 'status' => RefundStatus::Approved, 'amount_minor' => 5000, 'stripe_refund_id' => 're_1',
        ])];
    });

    deliverStripeEvent(['id' => 'evt_1', 'type' => 'refund.updated', 'data' => ['object' => [
        'id' => 're_1', 'object' => 'refund', 'status' => 'succeeded', 'metadata' => ['tenant_id' => $tenant->id, 'refund_id' => (string) $refund->id],
    ]]])->assertOk()->assertJson(['status' => 'handled']);

    expect(withinTenant($tenant, fn (): array => [$refund->fresh()?->status, $booking->fresh()?->amount_refunded_minor]))
        ->toBe([RefundStatus::Processed, 5000]);
});

test('the webhook is only on the platform\'s domain, not on an agency\'s', function () {
    $tenant = tenantOnDomain();

    deliverStripeEvent(invoicePaidEvent($tenant, 'DUMA-2026-AAAAAA', 19900), host: "{$tenant->id}.localhost")->assertNotFound();
});
