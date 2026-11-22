<?php

use App\Enums\BookingApprovalStatus;
use App\Enums\ItemStatus;
use App\Enums\NotificationType;
use App\Enums\Role;
use App\Events\BookingApproved;
use App\Events\BookingRejected;
use App\Events\CustomerRegistered;
use App\Events\ListingDecided;
use App\Events\ProfileUpdated;
use App\Models\Booking;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Refund;
use App\Models\User;
use App\Services\Booking\BookingDraftLine;
use App\Services\Payment\RefundService;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\travelTo;

/**
 * The agency's notifications as [user, type, message, subject type, subject
 * id], oldest first. Call inside the agency.
 *
 * @return list<array{0: int, 1: NotificationType, 2: string, 3: string|null, 4: int|null}>
 */
function notificationFeed(): array
{
    return Notification::query()->orderBy('id')->get()->map(fn (Notification $notification): array => [
        $notification->user_id, $notification->notification_type, $notification->message, $notification->subject_type, $notification->subject_id,
    ])->all();
}

/**
 * The [user, message] of each notification of the type, oldest first. Call
 * inside the agency.
 *
 * @return list<array{0: int, 1: string}>
 */
function notificationsOf(NotificationType $type): array
{
    return Notification::query()->where('notification_type', $type)->orderBy('id')->get()
        ->map(fn (Notification $notification): array => [$notification->user_id, $notification->message])->all();
}

test('a new booking tells its customer, and the agency\'s active managers and admins', function () {
    travelTo('2026-11-03 10:00:00');
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create(['first_name' => 'Beatriz', 'middle_name' => null, 'last_name' => 'Alonzo']);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    User::factory()->role(Role::Staff)->for($tenant)->create();
    User::factory()->role(Role::Manager)->inactive()->for($tenant)->create();

    $booking = withinTenant($tenant, fn (): Booking => bookFor($customer, [new BookingDraftLine(seatOnTour()->id)]));

    $forDeciders = "Beatriz Alonzo booked {$booking->reference} for ₱499.00. It is waiting for approval.";
    expect(withinTenant($tenant, fn (): array => notificationFeed()))->toBe([
        [$customer->id, NotificationType::BookingCreated, "We have your booking {$booking->reference} for ₱499.00, and will let you know once it is confirmed.", 'booking', $booking->id],
        [$admin->id, NotificationType::BookingCreated, $forDeciders, 'booking', $booking->id],
        [$manager->id, NotificationType::BookingCreated, $forDeciders, 'booking', $booking->id],
    ]);
});

test('a payment tells the customer what came in and what is still owed', function (string $amount, string $message) {
    $tenant = tenantOnDomain();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('599.00', 'PHP'), 'reference' => 'DGTE-2026-0002']));

    takeCash($tenant, $booking, $amount, $staff);

    expect(withinTenant($tenant, fn (): array => notificationFeed()))
        ->toBe([[$booking->customer_id, NotificationType::PaymentReceived, $message, 'booking', $booking->id]]);
})->with([
    'a deposit' => ['400.00', 'We have received your payment of ₱400.00 for DGTE-2026-0002. Balance due: ₱199.00.'],
    'all of it' => ['599.00', 'We have received your payment of ₱599.00. DGTE-2026-0002 is paid in full.'],
]);

test('defect 2: the money in a notification reads as money, never as float residue', function () {
    $tenant = tenantOnDomain();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('599.00', 'PHP'), 'reference' => 'DGTE-2026-0002']));

    takeCash($tenant, $booking, '30.01', $staff);

    expect(withinTenant($tenant, fn (): string => Notification::query()->sole()->message))
        ->toBe('We have received your payment of ₱30.01 for DGTE-2026-0002. Balance due: ₱568.99.')
        ->not->toContain('30.0099');
});

test('a refund request tells the agency\'s managers and admins why it is asked', function () {
    $tenant = tenantOnDomain();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('599.00', 'PHP'), 'reference' => 'DGTE-2026-0002']));
    takeCash($tenant, $booking, '599.00', $manager);

    withinTenant($tenant, fn (): Refund => app(RefundService::class)->request($booking, Money::parse('100.00', 'PHP'), 'Travelling companion hospitalised.', $manager));

    expect(withinTenant($tenant, fn (): array => notificationsOf(NotificationType::RefundRequested)))
        ->toBe([[$manager->id, '₱100.00 refund asked for on DGTE-2026-0002. Reason: Travelling companion hospitalised.']]);
});

test('each decision on a refund, and its payout, tells the customer', function (Closure $decide, NotificationType $type, string $message) {
    $tenant = tenantOnDomain();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create(['total' => Money::parse('599.00', 'PHP'), 'reference' => 'DGTE-2026-0002']));
    takeCash($tenant, $booking, '599.00', $manager);
    $refund = withinTenant($tenant, fn (): Refund => app(RefundService::class)->request($booking, Money::parse('100.00', 'PHP'), 'Changed plans.', $manager));

    withinTenant($tenant, fn (): Refund => $decide(app(RefundService::class), $refund, $manager));

    expect(withinTenant($tenant, fn (): array => notificationsOf($type)))->toBe([[$booking->customer_id, $message]]);
})->with([
    'approved' => [
        fn (RefundService $refunds, Refund $refund, User $manager): Refund => $refunds->approve($refund, $manager),
        NotificationType::RefundApproved,
        'Your refund of ₱100.00 on DGTE-2026-0002 was approved.',
    ],
    'rejected' => [
        fn (RefundService $refunds, Refund $refund, User $manager): Refund => $refunds->reject($refund, $manager, 'Outside the 48-hour cancellation window.'),
        NotificationType::RefundRejected,
        'Your refund request on DGTE-2026-0002 was declined. Reason: Outside the 48-hour cancellation window.',
    ],
    'paid out' => [
        fn (RefundService $refunds, Refund $refund, User $manager): Refund => $refunds->process($refunds->approve($refund, $manager)),
        NotificationType::RefundProcessed,
        '₱100.00 has been refunded on DGTE-2026-0002.',
    ],
]);

test('the events the later workflows will dispatch each write their notification', function (Closure $arrange, NotificationType $type, string $message, string $subjectType) {
    $tenant = tenantOnDomain();
    [$event, $recipientId, $subjectId] = withinTenant($tenant, fn (): array => $arrange());

    withinTenant($tenant, fn (): mixed => event($event));

    expect(withinTenant($tenant, fn (): array => notificationFeed()))->toBe([[$recipientId, $type, $message, $subjectType, $subjectId]]);
})->with([
    'booking approved (TM-2)' => [function (): array {
        $booking = Booking::factory()->approved()->create(['reference' => 'DGTE-2026-0002']);

        return [new BookingApproved($booking), $booking->customer_id, $booking->id];
    }, NotificationType::BookingApproved, 'Your booking DGTE-2026-0002 is confirmed.', 'booking'],
    'booking rejected (TM-2)' => [function (): array {
        $booking = Booking::factory()->create(['reference' => 'DGTE-2026-0002', 'approval_status' => BookingApprovalStatus::Rejected, 'rejection_reason' => 'Fully booked that week.']);

        return [new BookingRejected($booking), $booking->customer_id, $booking->id];
    }, NotificationType::BookingRejected, 'Your booking DGTE-2026-0002 was declined. Reason: Fully booked that week.', 'booking'],
    'listing approved (TM-1)' => [function (): array {
        $staff = User::factory()->role(Role::Staff)->create(['tenant_id' => tenant()?->getTenantKey()]);
        $item = Item::factory()->create(['name' => 'Japan Discovery', 'item_status' => ItemStatus::Approved, 'created_by' => $staff->id]);

        return [new ListingDecided($item), $staff->id, $item->id];
    }, NotificationType::Approval, 'Japan Discovery was approved.', 'item'],
    'listing rejected (TM-1)' => [function (): array {
        $staff = User::factory()->role(Role::Staff)->create(['tenant_id' => tenant()?->getTenantKey()]);
        $item = Item::factory()->create(['name' => 'Japan Discovery', 'item_status' => ItemStatus::Rejected, 'rejection_reason' => 'Add the departure dates.', 'created_by' => $staff->id]);

        return [new ListingDecided($item), $staff->id, $item->id];
    }, NotificationType::Approval, 'Japan Discovery was rejected. Reason: Add the departure dates.', 'item'],
    'customer registered (customer API)' => [function (): array {
        $customer = User::factory()->role(Role::Customer)->create(['tenant_id' => tenant()?->getTenantKey(), 'first_name' => 'Beatriz']);

        return [new CustomerRegistered($customer), $customer->id, $customer->id];
    }, NotificationType::UserRegistered, 'Welcome, Beatriz! Your account has been created.', 'user'],
    'profile updated (customer API)' => [function (): array {
        $customer = User::factory()->role(Role::Customer)->create(['tenant_id' => tenant()?->getTenantKey()]);

        return [new ProfileUpdated($customer), $customer->id, $customer->id];
    }, NotificationType::ProfileUpdated, 'Your profile details were changed. If this was not you, contact the agency.', 'user'],
]);

test('a change that rolls back notifies no one', function () {
    travelTo('2026-11-03 10:00:00');
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();

    expect(fn () => withinTenant($tenant, fn () => DB::transaction(function () use ($customer): void {
        bookFor($customer, [new BookingDraftLine(seatOnTour()->id)]);

        throw new RuntimeException('Card declined.');
    })))->toThrow(RuntimeException::class, 'Card declined.');

    expect(withinTenant($tenant, fn (): array => [Booking::query()->count(), Notification::query()->count()]))->toBe([0, 0]);
});

test('an event outside any agency writes nothing, and does not fail', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    expect(fn () => event(new ProfileUpdated($superAdmin)))->not->toThrow(Throwable::class)
        ->and(tenant())->toBeNull();
});
