<?php

use App\Enums\BookingApprovalStatus;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Booking;
use App\Models\InventoryAllocation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingDraftLine;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Open the agency's bookings page as the user, from within the agency.
 *
 * @param  array<string, string>  $query
 */
function agencyBookings(Tenant $tenant, User $user, array $query = []): Testable
{
    tenancy()->initialize($tenant);

    return Livewire::withQueryParams($query)->actingAs($user)->test('pages::tenant.bookings');
}

test('staff open the bookings page on the agency\'s domain, and another agency\'s manager is refused', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);

    actingAs(User::factory()->role(Role::Staff)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/bookings")
        ->assertOk()
        ->assertSee('Bookings');

    tenancy()->end();

    actingAs(User::factory()->role(Role::Manager)->for(tenantOnDomain(tier: PlanTier::Basic))->create())
        ->get("http://{$tenant->id}.localhost/bookings")
        ->assertForbidden();
});

test('the page opens on the bookings waiting for a decision, longest waiting first, and filters and searches', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    tenancy()->initialize($tenant);
    Booking::factory()->create(['reference' => 'DGTE-2026-AAAAAA']);
    Booking::factory()->create(['reference' => 'DGTE-2026-BBBBBB']);
    Booking::factory()->approved()->create(['reference' => 'DGTE-2026-CCCCCC']);

    $page = agencyBookings($tenant, User::factory()->role(Role::Manager)->for($tenant)->create())
        ->assertSeeInOrder(['DGTE-2026-AAAAAA', 'DGTE-2026-BBBBBB'])
        ->assertDontSee('DGTE-2026-CCCCCC');

    $page->set('status', (string) BookingApprovalStatus::Approved->value)->assertSee('DGTE-2026-CCCCCC')->assertDontSee('DGTE-2026-AAAAAA');
    $page->set('status', '')->set('search', 'BBBB')->assertSee('DGTE-2026-BBBBBB')->assertDontSee('DGTE-2026-AAAAAA');
});

test('the manager approves a waiting booking', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    tenancy()->initialize($tenant);
    $booking = Booking::factory()->create();

    agencyBookings($tenant, User::factory()->role(Role::Manager)->for($tenant)->create())
        ->call('confirmApprove', $booking->id)
        ->call('approve')
        ->assertHasNoErrors();

    expect($booking->fresh()?->approval_status)->toBe(BookingApprovalStatus::Approved);
});

test('rejecting without a reason says what is missing, and with one frees the booking\'s units', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    tenancy()->initialize($tenant);
    $booking = bookFor($customer, [new BookingDraftLine(seatOnTour()->id)]);

    $page = agencyBookings($tenant, User::factory()->role(Role::Manager)->for($tenant)->create())
        ->call('confirmReject', $booking->id)
        ->call('reject')
        ->assertHasErrors(['rejection_reason' => 'Tell the customer why the booking is declined.']);

    $page->set('rejection_reason', 'The tour is full.')->call('reject')->assertHasNoErrors();

    expect([$booking->fresh()?->approval_status, InventoryAllocation::query()->count()])->toBe([BookingApprovalStatus::Rejected, 0]);
});

test('staff see the bookings but cannot approve one', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    tenancy()->initialize($tenant);
    $booking = Booking::factory()->create();

    agencyBookings($tenant, User::factory()->role(Role::Staff)->for($tenant)->create())
        ->assertSee($booking->reference)
        ->assertDontSeeHtml("confirmApprove({$booking->id})")
        ->call('confirmApprove', $booking->id)
        ->assertForbidden();

    expect($booking->fresh()?->approval_status)->toBe(BookingApprovalStatus::Pending);
});

test('without Bookings in its plan a manager cannot approve, but can still cancel a hold', function () {
    $tenant = tenantOnDomain(tier: null);
    tenancy()->initialize($tenant);
    $booking = Booking::factory()->create();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();

    agencyBookings($tenant, $manager)
        ->assertSee('You can view these records, but not add or change them.')
        ->call('confirmApprove', $booking->id)
        ->assertForbidden();

    agencyBookings($tenant, $manager)->call('confirmCancel', $booking->id)->call('cancel')->assertHasNoErrors();

    expect($booking->fresh()?->approval_status)->toBe(BookingApprovalStatus::Cancelled);
});

test('a closed agency\'s bookings show their reference but hide the customer and the money', function () {
    $tenant = tenantOnDomain(TenantStatus::Closed, PlanTier::Basic);
    tenancy()->initialize($tenant);
    $booking = Booking::factory()->create(['reference' => 'DGTE-2026-AAAAAA', 'customer_name' => 'Beatriz Alonzo']);

    agencyBookings($tenant, User::factory()->role(Role::Manager)->for($tenant)->create())
        ->assertSee($booking->reference)
        ->assertDontSee(['Beatriz Alonzo', $booking->customer_email, '₱499.00']);
});
