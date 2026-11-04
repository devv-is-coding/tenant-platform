<?php

use App\Enums\AuditAction;
use App\Enums\BookingApprovalStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\RefundStatus;
use App\Enums\RefundType;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Booking;
use App\Models\InventoryAllocation;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingDraftLine;
use App\Services\Payment\PaymentService;
use App\Services\Payment\RefundService;
use App\ValueObjects\Money;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\freezeTime;

/**
 * Ask for, approve and process a refund of the decimal amount, inside the agency.
 */
function refundInFull(Tenant $tenant, Booking $booking, string $amount, User $manager, ?Payment $payment = null): Refund
{
    return withinTenant($tenant, function () use ($booking, $amount, $manager, $payment): Refund {
        $refunds = app(RefundService::class);
        $refund = $refunds->request($booking, Money::parse($amount, 'PHP'), 'Changed plans.', $manager, $payment);

        return $refunds->process($refunds->approve($refund, $manager));
    });
}

test('defect 9: two partial refunds in turn on one booking are both recorded, and both counted', function () {
    $tenant = tenantOnDomain();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('599.00', 'PHP')]));
    takeCash($tenant, $booking, '599.00', $manager);

    refundInFull($tenant, $booking, '100.00', $manager);
    $afterFirst = withinTenant($tenant, fn (): ?Booking => $booking->fresh());
    refundInFull($tenant, $booking, '150.00', $manager);

    $after = withinTenant($tenant, fn (): array => [
        'refunds' => Refund::query()->orderBy('id')->get()->map(fn (Refund $refund): array => [$refund->type, $refund->status, $refund->amount_minor])->all(),
        'booking' => $booking->fresh(),
    ]);

    expect($afterFirst?->amount_refunded_minor)->toBe(10000)
        ->and($after['refunds'])->toBe([
            [RefundType::Partial, RefundStatus::Processed, 10000],
            [RefundType::Partial, RefundStatus::Processed, 15000],
        ])
        ->and($after['booking']?->amount_refunded_minor)->toBe(25000)
        ->and($after['booking']?->amount_paid_minor)->toBe(59900)
        ->and($after['booking']?->payment_status)->toBe(BookingPaymentStatus::PartiallyRefunded)
        ->and($after['booking']?->approval_status)->toBe(BookingApprovalStatus::Pending);
});

test('a refund cannot ask for more than was paid less what has gone back or is on its way', function () {
    $tenant = tenantOnDomain();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('599.00', 'PHP')]));
    takeCash($tenant, $booking, '400.00', $manager);
    refundInFull($tenant, $booking, '100.00', $manager);
    withinTenant($tenant, fn (): Refund => app(RefundService::class)->request($booking, Money::parse('200.00', 'PHP'), 'Room downgrade.', $manager));

    expect(fn (): Refund => withinTenant($tenant, fn (): Refund => app(RefundService::class)->request($booking, Money::parse('100.01', 'PHP'), 'One more.', $manager)))
        ->toThrow(ValidationException::class, 'The amount is more than the ₱100.00 that can still be refunded.');

    expect(withinTenant($tenant, fn (): int => Refund::query()->count()))->toBe(2);
});

test('a refund that returns everything paid ends the booking and frees its units for the next customer at once', function () {
    freezeTime();
    $tenant = tenantOnDomain();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    [$first, $second] = User::factory()->count(2)->role(Role::Customer)->for($tenant)->create()->all();

    [$seat, $booking] = withinTenant($tenant, function () use ($first): array {
        $seat = seatOnTour(1);

        return [$seat, bookFor($first, [new BookingDraftLine($seat->id)])];
    });
    takeCash($tenant, $booking, '499.00', $manager);

    $refund = refundInFull($tenant, $booking, '499.00', $manager);

    $outcome = withinTenant($tenant, function () use ($seat, $second, $booking): array {
        $rebooked = bookFor($second, [new BookingDraftLine($seat->id)]);

        return [
            'booking' => $booking->fresh(),
            'cancelled_at' => $booking->fresh()?->cancelled_at?->toDateTimeString(),
            'rebooked' => InventoryAllocation::query()->pluck('booking_line_id')->all() === $rebooked->lines()->pluck('id')->all(),
        ];
    });

    expect($refund->type)->toBe(RefundType::Full)
        ->and($outcome['booking']?->approval_status)->toBe(BookingApprovalStatus::Cancelled)
        ->and($outcome['cancelled_at'])->toBe(now()->toDateTimeString())
        ->and($outcome['booking']?->payment_status)->toBe(BookingPaymentStatus::Refunded)
        ->and($outcome['rebooked'])->toBeTrue();
});

test('a partial refund leaves the booking live and its units held', function () {
    $tenant = tenantOnDomain();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();

    $booking = withinTenant($tenant, fn (): Booking => bookFor($customer, [new BookingDraftLine(seatOnTour(1)->id)]));
    takeCash($tenant, $booking, '499.00', $manager);

    refundInFull($tenant, $booking, '50.00', $manager);

    expect(withinTenant($tenant, fn (): array => [$booking->fresh()?->approval_status, InventoryAllocation::query()->count()]))
        ->toBe([BookingApprovalStatus::Pending, 1]);
});

test('a manager may approve less than was asked, never more, and what is approved is what goes back', function () {
    $tenant = tenantOnDomain();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('599.00', 'PHP')]));
    takeCash($tenant, $booking, '599.00', $manager);
    $refund = withinTenant($tenant, fn (): Refund => app(RefundService::class)->request($booking, Money::parse('200.00', 'PHP'), 'Late pick-up.', $manager));

    expect(fn (): Refund => withinTenant($tenant, fn (): Refund => app(RefundService::class)->approve($refund, $manager, Money::parse('200.01', 'PHP'))))
        ->toThrow(ValidationException::class, 'Approve at most the ₱200.00 asked for.');

    $approved = withinTenant($tenant, fn (): Refund => app(RefundService::class)->process(app(RefundService::class)->approve($refund, $manager, Money::parse('120.00', 'PHP'))));

    expect($approved->amount_minor)->toBe(12000)
        ->and($approved->decided_by)->toBe($manager->id)
        ->and(withinTenant($tenant, fn (): ?int => $booking->fresh()?->amount_refunded_minor))->toBe(12000);
});

test('a rejected refund needs a reason, and leaves the money and the units as they were', function () {
    $tenant = tenantOnDomain();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();

    $refunds = app(RefundService::class);
    $booking = withinTenant($tenant, fn (): Booking => bookFor($customer, [new BookingDraftLine(seatOnTour(1)->id)]));
    takeCash($tenant, $booking, '499.00', $manager);
    $refund = withinTenant($tenant, fn (): Refund => $refunds->request($booking, Money::parse('499.00', 'PHP'), 'Changed plans.', $manager));

    expect(fn () => withinTenant($tenant, fn (): Refund => $refunds->reject($refund, $manager, '  ')))
        ->toThrow(ValidationException::class, 'Give the customer a reason for the rejection.');

    $rejected = withinTenant($tenant, fn (): Refund => $refunds->reject($refund, $manager, 'Inside the no-refund window.'));
    $after = withinTenant($tenant, fn (): array => [$booking->fresh(), InventoryAllocation::query()->count()]);

    expect($rejected->status)->toBe(RefundStatus::Rejected)
        ->and($rejected->decision_reason)->toBe('Inside the no-refund window.')
        ->and($after[0]?->amount_refunded_minor)->toBe(0)
        ->and($after[0]?->payment_status)->toBe(BookingPaymentStatus::Paid)
        ->and($after[0]?->approval_status)->toBe(BookingApprovalStatus::Pending)
        ->and($after[1])->toBe(1);
});

test('a refund must say why, and only a requested refund can be decided, only an approved one processed', function () {
    $tenant = tenantOnDomain();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create());
    takeCash($tenant, $booking, '100.00', $manager);

    $refunds = app(RefundService::class);

    expect(fn () => withinTenant($tenant, fn (): Refund => $refunds->request($booking, Money::parse('10.00', 'PHP'), '', $manager)))
        ->toThrow(ValidationException::class, 'Say why the refund is wanted.');

    $requested = withinTenant($tenant, fn (): Refund => $refunds->request($booking, Money::parse('10.00', 'PHP'), 'Changed plans.', $manager));

    expect(fn () => withinTenant($tenant, fn (): Refund => $refunds->process($requested)))
        ->toThrow(ValidationException::class, 'This refund is requested, not approved.');

    $processed = withinTenant($tenant, fn (): Refund => $refunds->process($refunds->approve($requested, $manager)));

    expect(fn () => withinTenant($tenant, fn (): Refund => $refunds->approve($processed, $manager)))
        ->toThrow(ValidationException::class, 'This refund is processed, not requested.');
});

test('a refund naming a payment cannot take more back than that payment brought in', function () {
    $tenant = tenantOnDomain();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('599.00', 'PHP')]));
    takeCash($tenant, $booking, '400.00', $manager);
    $balancePayment = takeCash($tenant, $booking, '199.00', $manager);

    expect(fn () => withinTenant($tenant, fn (): Refund => app(RefundService::class)->request($booking, Money::parse('199.01', 'PHP'), 'Changed plans.', $manager, $balancePayment)))
        ->toThrow(ValidationException::class, "The amount is more than the ₱199.00 left of payment {$balancePayment->reference}.");

    $refunded = refundInFull($tenant, $booking, '199.00', $manager, $balancePayment);

    expect($refunded->payment_id)->toBe($balancePayment->id)
        ->and(withinTenant($tenant, fn (): ?PaymentRecordStatus => $balancePayment->fresh()?->status))->toBe(PaymentRecordStatus::Refunded);
});

test('each step of a refund is recorded in the agency\'s log', function () {
    $tenant = tenantOnDomain();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('599.00', 'PHP'), 'reference' => 'DUMA-2026-AAAAAA']));
    takeCash($tenant, $booking, '599.00', $manager);

    refundInFull($tenant, $booking, '100.00', $manager);

    $logged = withinTenant($tenant, fn (): array => AuditEvent::query()
        ->whereIn('action', [AuditAction::RefundRequested, AuditAction::RefundApproved, AuditAction::RefundProcessed])
        ->orderBy('id')->pluck('description')->all());

    expect($logged)->toBe([
        '₱100.00 refund asked for on DUMA-2026-AAAAAA.',
        '₱100.00 partial refund approved on DUMA-2026-AAAAAA.',
        '₱100.00 refunded on DUMA-2026-AAAAAA.',
    ]);
});

test('randomised: after any run of payments and refunds the booking\'s amounts equal its ledgers, and never more goes back than came in', function () {
    $seed = random_int(1, PHP_INT_MAX);
    mt_srand($seed);
    $tenant = tenantOnDomain();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();

    $checks = withinTenant($tenant, function () use ($manager): array {
        $payments = app(PaymentService::class);
        $refunds = app(RefundService::class);
        $booking = Booking::factory()->create(['total' => Money::ofMinor(mt_rand(100, 500000), 'PHP')]);
        $checks = [];

        for ($step = 0; $step < 40; $step++) {
            $fresh = $booking->fresh() ?? throw new LogicException('The booking vanished.');

            try {
                if (mt_rand(0, 1) === 0 && $fresh->balance()->minor > 0) {
                    $payments->recordByStaff($fresh, Money::ofMinor(mt_rand(1, $fresh->balance()->minor), 'PHP'), PaymentMethod::Cash, now(), $manager);
                } else {
                    // Sometimes asks for more than can go back: that must be refused, not recorded.
                    $refund = $refunds->request($fresh, Money::ofMinor(mt_rand(1, max(1, $fresh->amount_paid_minor + 100)), 'PHP'), 'Randomised.', $manager);
                    match (mt_rand(0, 2)) {
                        0 => $refunds->process($refunds->approve($refund, $manager)),
                        1 => $refunds->reject($refund, $manager, 'Randomised.'),
                        default => null,
                    };
                }
            } catch (ValidationException) {
                // Refused steps change nothing; the checks below prove it.
            }

            $now = $booking->fresh() ?? throw new LogicException('The booking vanished.');
            $checks[] = [
                $now->amount_paid_minor === (int) Payment::query()->countingAsPaid()->sum('amount_minor'),
                $now->amount_refunded_minor === (int) Refund::query()->countingAsRefunded()->sum('amount_minor'),
                $now->amount_refunded_minor <= $now->amount_paid_minor,
                $now->payment_status === BookingPaymentStatus::fromAmounts($now->total_minor, $now->amount_paid_minor, $now->amount_refunded_minor),
            ];
        }

        return $checks;
    });

    expect($checks)->each->toBe([true, true, true, true], "Seed {$seed}");
});

test('a refund of a card payment goes back through Stripe, processed once Stripe says it succeeded', function (string $stripeStatus, RefundStatus $status, int $refundedMinor) {
    $tenant = tenantOnDomain();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    [$booking, $payment] = withinTenant($tenant, function (): array {
        $booking = Booking::factory()->create(['total' => Money::parse('199.00', 'PHP')]);
        $payment = Payment::factory()->viaStripe()->for($booking)->amount(Money::parse('199.00', 'PHP'))->create();
        $booking->recomputeMoney();

        return [$booking, $payment];
    });
    $sent = fakeStripe(['POST /v1/refunds' => ['id' => 're_1', 'object' => 'refund', 'status' => $stripeStatus]]);

    $refund = refundInFull($tenant, $booking, '50.00', $manager, $payment);

    expect($refund->only(['stripe_refund_id']))->toBe(['stripe_refund_id' => 're_1'])
        ->and($refund->status)->toBe($status)
        ->and($sent->count())->toBe(1)
        ->and(withinTenant($tenant, fn (): ?int => $booking->fresh()?->amount_refunded_minor))->toBe($refundedMinor);
})->with([
    'succeeded at once' => ['succeeded', RefundStatus::Processed, 5000],
    'still pending' => ['pending', RefundStatus::Approved, 0],
    'failed' => ['failed', RefundStatus::Failed, 0],
]);
