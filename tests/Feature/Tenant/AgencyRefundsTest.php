<?php

use App\Enums\AuditAction;
use App\Enums\PlanTier;
use App\Enums\RefundStatus;
use App\Enums\RefundType;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Tenant;
use App\Models\User;
use App\ValueObjects\Money;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Open the agency's refunds page as the user, from within the agency.
 */
function agencyRefunds(Tenant $tenant, User $user): Testable
{
    tenancy()->initialize($tenant);

    return Livewire::actingAs($user)->test('pages::tenant.refunds');
}

/**
 * A ₱1,000.00 booking paid in full, in cash or by card through Stripe, with a
 * refund of the decimal amount asked for against that payment, in the given
 * status. Call inside the agency.
 */
function refundAskedFor(string $amount, bool $byCard = false, RefundStatus $status = RefundStatus::Requested): Refund
{
    $booking = Booking::factory()->approved()->create(['reference' => 'DGTE-2026-0002', 'total' => Money::parse('1000.00', 'PHP')]);
    $payment = ($byCard ? Payment::factory()->viaStripe() : Payment::factory())->for($booking)->amount(Money::parse('1000.00', 'PHP'))->create();
    $booking->recomputeMoney();

    return Refund::factory()->for($booking)->for($payment)->create([
        'amount' => Money::parse($amount, 'PHP'),
        'status' => $status,
        'decided_at' => $status === RefundStatus::Requested ? null : now(),
    ]);
}

test('a manager opens the refunds page on the agency\'s domain; staff are refused', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);

    actingAs(User::factory()->role(Role::Manager)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/refunds")
        ->assertOk()
        ->assertSee('Refunds');

    tenancy()->end();

    actingAs(User::factory()->role(Role::Staff)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/refunds")
        ->assertForbidden();
});

test('the manager approves less than was asked, and the decision is audited', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    tenancy()->initialize($tenant);
    $refund = refundAskedFor('400.00');

    agencyRefunds($tenant, $manager)
        ->call('confirmApprove', $refund->id)
        ->assertSet('amount', '400.00')
        ->assertSee('₱400.00 asked for; ₱1,000.00 can still be refunded on the booking.')
        ->set('amount', '250.00')
        ->call('approve')
        ->assertHasNoErrors();

    expect($refund->fresh()?->only(['status', 'amount_minor', 'type', 'decided_by']))->toBe(['status' => RefundStatus::Approved, 'amount_minor' => 25000, 'type' => RefundType::Partial, 'decided_by' => $manager->id])
        ->and(AuditEvent::query()->where('action', AuditAction::RefundApproved)->sole()->actor_id)->toBe($manager->id);
});

test('approving more than was asked is refused with a clean message, and the refund stays asked for', function (string $amount, string $message) {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    tenancy()->initialize($tenant);
    $refund = refundAskedFor('400.00');

    agencyRefunds($tenant, User::factory()->role(Role::Manager)->for($tenant)->create())
        ->call('confirmApprove', $refund->id)
        ->set('amount', $amount)
        ->call('approve')
        ->assertHasErrors(['amount' => $message]);

    expect($refund->fresh()?->status)->toBe(RefundStatus::Requested);
})->with([
    'more than asked' => ['400.01', 'Approve at most the ₱400.00 asked for.'],
    'not a number' => ['1,000', 'Enter the amount as a number with at most two decimal places, without commas.'],
    'zero' => ['0.00', 'The amount must be more than zero.'],
]);

test('rejecting needs a reason, and with one is audited and leaves the money where it was', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    tenancy()->initialize($tenant);
    $refund = refundAskedFor('400.00');

    $page = agencyRefunds($tenant, User::factory()->role(Role::Manager)->for($tenant)->create())
        ->call('confirmReject', $refund->id)
        ->call('reject')
        ->assertHasErrors(['decision_reason' => 'Give the customer a reason for the rejection.']);

    $page->set('decision_reason', 'Outside the cancellation window.')->call('reject')->assertHasNoErrors();

    expect($refund->fresh()?->only(['status', 'decision_reason']))->toBe(['status' => RefundStatus::Rejected, 'decision_reason' => 'Outside the cancellation window.'])
        ->and($refund->booking()->firstOrFail()->amount_refunded_minor)->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditAction::RefundRejected)->count())->toBe(1);
});

test('staff cannot decide a refund', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    tenancy()->initialize($tenant);
    $refund = refundAskedFor('400.00');

    Livewire::actingAs(User::factory()->role(Role::Staff)->for($tenant)->create())
        ->test('pages::tenant.refunds')
        ->assertForbidden();

    expect($refund->fresh()?->status)->toBe(RefundStatus::Requested);
});

test('a cash refund is paid back by hand on a tier without Stripe refunds', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    tenancy()->initialize($tenant);
    $refund = refundAskedFor('400.00', status: RefundStatus::Approved);

    agencyRefunds($tenant, User::factory()->role(Role::Manager)->for($tenant)->create())
        ->call('confirmProcess', $refund->id)
        ->assertSee('Record as paid back')
        ->call('processByHand')
        ->assertHasNoErrors();

    expect($refund->fresh()?->status)->toBe(RefundStatus::Processed)
        ->and($refund->booking()->firstOrFail()->amount_refunded_minor)->toBe(40000);
});

test('a card refund goes back through Stripe on a tier with Stripe refunds', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Advanced);
    tenancy()->initialize($tenant);
    $refund = refundAskedFor('400.00', byCard: true, status: RefundStatus::Approved);
    $sent = fakeStripe(['POST /v1/refunds' => ['id' => 're_1', 'object' => 'refund', 'status' => 'succeeded']]);

    agencyRefunds($tenant, User::factory()->role(Role::Manager)->for($tenant)->create())
        ->call('confirmProcess', $refund->id)
        ->call('processThroughStripe')
        ->assertHasNoErrors();

    expect($refund->fresh()?->only(['status', 'stripe_refund_id']))->toBe(['status' => RefundStatus::Processed, 'stripe_refund_id' => 're_1'])
        ->and($sent->count())->toBe(1);
});

test('without Stripe refunds a card refund cannot go through Stripe, but can be recorded as paid back another way', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    tenancy()->initialize($tenant);
    $refund = refundAskedFor('400.00', byCard: true, status: RefundStatus::Approved);
    $sent = fakeStripe();

    agencyRefunds($tenant, $manager)
        ->call('confirmProcess', $refund->id)
        ->assertDontSee('Refund through Stripe')
        ->call('processThroughStripe')
        ->assertForbidden();

    agencyRefunds($tenant, $manager)->call('confirmProcess', $refund->id)->call('processByHand')->assertHasNoErrors();

    expect([$refund->fresh()?->status, $sent->count()])->toBe([RefundStatus::Processed, 0]);
});
