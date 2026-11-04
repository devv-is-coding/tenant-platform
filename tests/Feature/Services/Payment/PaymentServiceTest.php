<?php

use App\Enums\AuditAction;
use App\Enums\BookingApprovalStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payment\PaymentService;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\freezeTime;

test('the walk-in flow: 400.00 cash then 199.00 settles a 599.00 booking, PartiallyPaid then Paid', function () {
    $tenant = tenantOnDomain();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('599.00', 'PHP')]));

    takeCash($tenant, $booking, '400.00', $staff);
    $afterDeposit = withinTenant($tenant, fn (): ?Booking => $booking->fresh());
    takeCash($tenant, $booking, '199.00', $staff);
    $settled = withinTenant($tenant, fn (): ?Booking => $booking->fresh());

    expect($afterDeposit?->payment_status)->toBe(BookingPaymentStatus::PartiallyPaid)
        ->and($afterDeposit?->amount_paid_minor)->toBe(40000)
        ->and($settled?->payment_status)->toBe(BookingPaymentStatus::Paid)
        ->and($settled?->amount_paid_minor)->toBe(59900)
        ->and(withinTenant($tenant, fn (): int => $booking->balance()->minor))->toBe(59900)
        ->and(withinTenant($tenant, fn (): ?int => $booking->fresh()?->balance()->minor))->toBe(0);
});

test('defect 2: 30.01 is stored as 3001 minor units, read back as 3001, and shown as 30.01', function () {
    $tenant = tenantOnDomain();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('599.00', 'PHP')]));

    $payment = takeCash($tenant, $booking, '30.01', $staff);

    $stored = withinTenant($tenant, fn (): array => [
        'payment' => DB::table('payments')->where('id', $payment->id)->value('amount_minor'),
        'booking' => DB::table('bookings')->where('id', $booking->id)->value('amount_paid_minor'),
        'rows' => json_encode([DB::table('payments')->get(), DB::table('bookings')->get()]),
        'read' => $payment->fresh()?->amount,
    ]);

    expect((int) $stored['payment'])->toBe(3001)
        ->and((int) $stored['booking'])->toBe(3001)
        ->and($stored['read']?->minor)->toBe(3001)
        ->and($stored['read']?->toDecimal())->toBe('30.01')
        ->and($stored['read']?->format('en'))->toBe('₱30.01')
        ->and($stored['rows'])->not->toContain('30.0099');
});

test('staff cannot take more than is still owed', function () {
    $tenant = tenantOnDomain();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('599.00', 'PHP')]));
    takeCash($tenant, $booking, '400.00', $staff);

    expect(fn (): Payment => takeCash($tenant, $booking, '199.01', $staff))
        ->toThrow(ValidationException::class, 'The amount is more than the ₱199.00 still owed.');

    expect(withinTenant($tenant, fn (): int => Payment::query()->count()))->toBe(1);
});

test('card and Stripe payments are never recorded by hand', function (PaymentMethod $method) {
    $tenant = tenantOnDomain();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create());

    expect(fn () => withinTenant($tenant, fn (): Payment => app(PaymentService::class)->recordByStaff($booking, Money::parse('100.00', 'PHP'), $method, now(), $staff)))
        ->toThrow(ValidationException::class, "{$method->label()} payments are confirmed by the payment provider, never recorded by hand.");
})->with([
    'card' => PaymentMethod::CreditCard,
    'Stripe' => PaymentMethod::Stripe,
]);

test('a bank transfer is recorded by hand like cash', function () {
    $tenant = tenantOnDomain();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create());

    $payment = withinTenant($tenant, fn (): Payment => app(PaymentService::class)->recordByStaff($booking, Money::parse('100.00', 'PHP'), PaymentMethod::BankTransfer, now(), $staff));

    expect($payment->method)->toBe(PaymentMethod::BankTransfer)
        ->and($payment->recorded_by)->toBe($staff->id);
});

test('a booking no longer live takes no payment', function (BookingApprovalStatus $status) {
    $tenant = tenantOnDomain();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['approval_status' => $status, 'reference' => 'DUMA-2026-AAAAAA']));

    expect(fn (): Payment => takeCash($tenant, $booking, '100.00', $staff))
        ->toThrow(ValidationException::class, 'DUMA-2026-AAAAAA is '.mb_strtolower($status->label()).' and takes no payments.');
})->with([
    'rejected' => BookingApprovalStatus::Rejected,
    'cancelled' => BookingApprovalStatus::Cancelled,
    'expired' => BookingApprovalStatus::Expired,
]);

test('a payment must be more than zero, in the booking\'s currency, and not dated in the future', function (Money $amount, ?CarbonImmutable $paidAt, string $message) {
    freezeTime();
    $tenant = tenantOnDomain();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['reference' => 'DUMA-2026-AAAAAA']));

    expect(fn () => withinTenant($tenant, fn (): Payment => app(PaymentService::class)->recordByStaff($booking, $amount, PaymentMethod::Cash, $paidAt ?? now(), $staff)))
        ->toThrow(ValidationException::class, $message);
})->with([
    'zero' => fn (): array => [Money::zero('PHP'), null, 'The amount must be more than zero.'],
    'another currency' => fn (): array => [Money::parse('100.00', 'USD'), null, 'DUMA-2026-AAAAAA was booked in PHP.'],
    'tomorrow' => fn (): array => [Money::parse('100.00', 'PHP'), CarbonImmutable::now()->addDay(), 'The payment date cannot be in the future.'],
]);

test('a cash payment is recorded in the agency\'s log by the staff member, with the money it moved', function () {
    $tenant = tenantOnDomain();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('599.00', 'PHP'), 'reference' => 'DUMA-2026-AAAAAA']));
    actingAs($staff);

    $payment = takeCash($tenant, $booking, '400.00', $staff);
    $event = withinTenant($tenant, fn (): AuditEvent => AuditEvent::query()->where('action', AuditAction::PaymentRecorded)->sole());

    expect($payment->reference)->toBe('DUMA-2026-AAAAAA-P1')
        ->and($event->actor_id)->toBe($staff->id)
        ->and($event->description)->toBe('₱400.00 cash received for DUMA-2026-AAAAAA.')
        ->and($event->changes)->toBe([
            'amount_paid_minor' => ['old' => 0, 'new' => 40000],
            'payment_status' => ['old' => BookingPaymentStatus::Unpaid->value, 'new' => BookingPaymentStatus::PartiallyPaid->value],
        ]);
});

test('a Stripe payment delivered twice is credited once', function () {
    $tenant = tenantOnDomain();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('199.00', 'PHP')]));

    $credited = withinTenant($tenant, function () use ($booking): array {
        $first = app(PaymentService::class)->recordFromGateway($booking, Money::parse('199.00', 'PHP'), 'stripe:invoice:in_1', now(), 'pi_1');
        $second = app(PaymentService::class)->recordFromGateway($booking, Money::parse('199.00', 'PHP'), 'stripe:invoice:in_1', now(), 'pi_1');

        return [$first->id, $second->id, Payment::query()->count(), $booking->fresh()?->amount_paid_minor];
    });

    expect($credited)->toBe([$credited[0], $credited[0], 1, 19900]);
});

test('money Stripe took beyond the total is still recorded, and the overpayment noted', function () {
    $tenant = tenantOnDomain();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('599.00', 'PHP')]));
    takeCash($tenant, $booking, '400.00', $staff);

    $recorded = withinTenant($tenant, function () use ($booking): array {
        $payment = app(PaymentService::class)->recordFromGateway($booking, Money::parse('599.00', 'PHP'), 'stripe:invoice:in_1', now(), 'pi_1');

        return [
            'payment' => $payment,
            'booking' => $booking->fresh(),
            'context' => AuditEvent::query()->where('action', AuditAction::PaymentReceived)->sole()->context,
        ];
    });

    expect($recorded['payment']->method)->toBe(PaymentMethod::Stripe)
        ->and($recorded['payment']->recorded_by)->toBeNull()
        ->and($recorded['booking']?->amount_paid_minor)->toBe(99900)
        ->and($recorded['booking']?->payment_status)->toBe(BookingPaymentStatus::Paid)
        ->and($recorded['context'])->toMatchArray(['overpaid_minor' => 40000, 'booking_active' => true]);
});

test('a Stripe payment in another currency than the booking\'s is refused', function () {
    $tenant = tenantOnDomain();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create());

    expect(fn () => withinTenant($tenant, fn (): Payment => app(PaymentService::class)->recordFromGateway($booking, Money::parse('100.00', 'USD'), 'stripe:invoice:in_1', now())))
        ->toThrow(InvalidArgumentException::class);

    expect(withinTenant($tenant, fn (): int => Payment::query()->count()))->toBe(0);
});
