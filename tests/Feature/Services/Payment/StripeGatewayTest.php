<?php

use App\Enums\AuditAction;
use App\Enums\BookingApprovalStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\Payment\StripeGateway;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\DB;

/**
 * Stripe's answers for a new balance invoice, in_new, for the given amount.
 *
 * @return array<string, array<string, mixed>>
 */
function newBalanceInvoice(int $amountMinor): array
{
    return [
        'POST /v1/customers' => ['id' => 'cus_new', 'object' => 'customer'],
        'POST /v1/invoices' => ['id' => 'in_new', 'object' => 'invoice', 'status' => 'draft'],
        'POST /v1/invoiceitems' => ['id' => 'ii_new', 'object' => 'invoiceitem'],
        'POST /v1/invoices/in_new/finalize' => [
            'id' => 'in_new',
            'object' => 'invoice',
            'status' => 'open',
            'amount_remaining' => $amountMinor,
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/in_new',
        ],
    ];
}

test('a payment link charges only the balance, and names the agency and booking for the webhook', function () {
    $tenant = tenantOnDomain();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('599.00', 'PHP'), 'reference' => 'DUMA-2026-AAAAAA']));
    takeCash($tenant, $booking, '400.00', $staff);
    $sent = fakeStripe(newBalanceInvoice(19900));

    $url = withinTenant($tenant, fn (): string => app(StripeGateway::class)->balanceInvoiceUrl($booking->fresh() ?? $booking));

    $calls = collect($sent->getArrayCopy())->keyBy(0);
    $stored = withinTenant($tenant, fn (): array => [
        $booking->fresh()?->only(['stripe_customer_id', 'stripe_invoice_id', 'stripe_invoice_url']),
        AuditEvent::query()->where('action', AuditAction::PaymentLinkGenerated)->count(),
    ]);

    expect($url)->toBe('https://invoice.stripe.com/i/in_new')
        ->and($calls['POST /v1/invoiceitems'][2])->toMatchArray(['amount' => 19900, 'currency' => 'php', 'invoice' => 'in_new'])
        ->and($calls['POST /v1/invoices'][2]['metadata'])->toBe(['tenant_id' => $tenant->id, 'booking_reference' => 'DUMA-2026-AAAAAA'])
        ->and($calls['POST /v1/invoices'][3])->toBe("invoice:{$tenant->id}:DUMA-2026-AAAAAA:40000")
        ->and($stored)->toBe([
            ['stripe_customer_id' => 'cus_new', 'stripe_invoice_id' => 'in_new', 'stripe_invoice_url' => 'https://invoice.stripe.com/i/in_new'],
            1,
        ]);
});

test('asking again for the same balance reuses the open invoice', function () {
    $tenant = tenantOnDomain();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create([
        'total' => Money::parse('199.00', 'PHP'),
        'stripe_invoice_id' => 'in_open',
    ]));
    $sent = fakeStripe(['GET /v1/invoices/in_open' => [
        'id' => 'in_open', 'object' => 'invoice', 'status' => 'open', 'amount_remaining' => 19900, 'hosted_invoice_url' => 'https://invoice.stripe.com/i/in_open',
    ]]);

    $url = withinTenant($tenant, fn (): string => app(StripeGateway::class)->balanceInvoiceUrl($booking));

    expect($url)->toBe('https://invoice.stripe.com/i/in_open')
        ->and(array_column($sent->getArrayCopy(), 0))->toBe(['GET /v1/invoices/in_open']);
});

test('an open invoice for an old balance is voided and replaced, so the old figure can no longer be paid', function () {
    $tenant = tenantOnDomain();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create([
        'total' => Money::parse('199.00', 'PHP'),
        'stripe_invoice_id' => 'in_old',
        'stripe_customer_id' => 'cus_old',
    ]));
    $sent = fakeStripe([
        'GET /v1/invoices/in_old' => ['id' => 'in_old', 'object' => 'invoice', 'status' => 'open', 'amount_remaining' => 59900, 'hosted_invoice_url' => 'https://invoice.stripe.com/i/in_old'],
        'POST /v1/invoices/in_old/void' => ['id' => 'in_old', 'object' => 'invoice', 'status' => 'void'],
        ...newBalanceInvoice(19900),
    ]);

    $url = withinTenant($tenant, fn (): string => app(StripeGateway::class)->balanceInvoiceUrl($booking));

    expect($url)->toBe('https://invoice.stripe.com/i/in_new')
        ->and(array_column($sent->getArrayCopy(), 0))->toBe([
            'GET /v1/invoices/in_old',
            'POST /v1/invoices/in_old/void',
            'POST /v1/invoices',
            'POST /v1/invoiceitems',
            'POST /v1/invoices/in_new/finalize',
        ]);
});

test('no link is made for a booking with nothing owed, or one no longer live', function (array $attributes, string $message) {
    $tenant = tenantOnDomain();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['reference' => 'DUMA-2026-AAAAAA', ...$attributes]));
    $sent = fakeStripe();

    expect(fn () => withinTenant($tenant, fn (): string => app(StripeGateway::class)->balanceInvoiceUrl($booking)))
        ->toThrow(InvalidArgumentException::class, $message);

    expect($sent->count())->toBe(0);
})->with([
    'settled' => [['total_minor' => 49900, 'amount_paid_minor' => 49900], 'DUMA-2026-AAAAAA has nothing left to pay.'],
    'cancelled' => [['approval_status' => BookingApprovalStatus::Cancelled], 'DUMA-2026-AAAAAA is cancelled and takes no payments.'],
]);

test('Stripe is never called inside a database transaction', function () {
    $tenant = tenantOnDomain();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create());
    $sent = fakeStripe();

    expect(fn () => withinTenant($tenant, fn (): string => DB::transaction(fn (): string => app(StripeGateway::class)->balanceInvoiceUrl($booking))))
        ->toThrow(LogicException::class, 'Stripe is never called inside a database transaction');

    expect($sent->count())->toBe(0);
});

test('a refund goes back through the payment it reverses, keyed so a retry cannot refund twice', function () {
    $tenant = tenantOnDomain();
    [$refund, $payment] = withinTenant($tenant, function (): array {
        $payment = Payment::factory()->viaStripe()->create(['stripe_payment_intent_id' => 'pi_1']);

        return [Refund::factory()->for($payment->booking)->for($payment)->create(['amount_minor' => 5000]), $payment];
    });
    $sent = fakeStripe(['POST /v1/refunds' => ['id' => 're_1', 'object' => 'refund', 'status' => 'succeeded']]);

    $answer = withinTenant($tenant, fn (): array => app(StripeGateway::class)->refund($refund, $payment));

    expect($answer)->toBe(['id' => 're_1', 'status' => 'succeeded'])
        ->and($sent[0][2])->toMatchArray(['payment_intent' => 'pi_1', 'amount' => 5000, 'metadata' => ['tenant_id' => $tenant->id, 'refund_id' => (string) $refund->id, 'payment_reference' => $payment->reference]])
        ->and($sent[0][3])->toBe("refund:{$tenant->id}:{$refund->id}");
});
