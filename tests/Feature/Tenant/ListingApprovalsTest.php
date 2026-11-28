<?php

use App\Enums\ItemStatus;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Models\Item;
use App\Models\Option;
use App\Models\Tenant;
use App\Models\User;
use App\ValueObjects\Money;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Open the listing approvals page as the user, from within the agency.
 */
function listingApprovals(Tenant $tenant, User $user): Testable
{
    tenancy()->initialize($tenant);

    return Livewire::actingAs($user)->test('pages::tenant.listing-approvals');
}

test('a manager opens the page on the agency\'s domain; staff are refused', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);

    actingAs(User::factory()->role(Role::Manager)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/listing-approvals")
        ->assertOk()
        ->assertSee('Listing approvals');

    tenancy()->end();

    actingAs(User::factory()->role(Role::Staff)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/listing-approvals")
        ->assertForbidden();
});

test('another agency\'s manager cannot open the page', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);

    actingAs(User::factory()->role(Role::Manager)->for(tenantOnDomain(tier: PlanTier::Basic))->create())
        ->get("http://{$tenant->id}.localhost/listing-approvals")
        ->assertForbidden();
});

test('the queue lists what waits for a decision and what waits to be published, never drafts', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    tenancy()->initialize($tenant);
    Item::factory()->pending()->create(['name' => 'Apo Island Snorkel']);
    Item::factory()->approved()->create(['name' => 'Twin Lakes Hike']);
    Item::factory()->create(['name' => 'Unfinished Draft']);

    listingApprovals($tenant, User::factory()->role(Role::Manager)->for($tenant)->create())
        ->assertSeeInOrder(['Waiting for a decision', 'Apo Island Snorkel', 'Approved, not on sale yet', 'Twin Lakes Hike'])
        ->assertDontSee('Unfinished Draft');
});

test('the manager approves a listing after seeing what it sells, then publishes it', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    tenancy()->initialize($tenant);
    $item = Item::factory()->pending()->create(['name' => 'Apo Island Snorkel']);
    Option::factory()->for($item)->create(['name' => 'Half day', 'price' => Money::parse('1500.00', 'PHP')]);

    $page = listingApprovals($tenant, User::factory()->role(Role::Manager)->for($tenant)->create())
        ->call('confirmApprove', $item->id)
        ->assertSee(['Half day', '₱1,500.00'])
        ->call('approve')
        ->assertHasNoErrors();
    $approved = $item->fresh()?->item_status;

    $page->call('confirmPublish', $item->id)->call('publish')->assertHasNoErrors();

    expect([$approved, $item->fresh()?->item_status])->toBe([ItemStatus::Approved, ItemStatus::Published]);
});

test('rejecting without a reason says what is missing, and with one sends the listing back', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    tenancy()->initialize($tenant);
    $item = Item::factory()->pending()->create();

    $page = listingApprovals($tenant, User::factory()->role(Role::Manager)->for($tenant)->create())
        ->call('confirmReject', $item->id)
        ->call('reject')
        ->assertHasErrors(['rejection_reason' => 'Tell the writer what to change.']);

    $page->set('rejection_reason', 'Add the pickup time.')->call('reject')->assertHasNoErrors();

    expect($item->fresh()?->only(['item_status', 'rejection_reason']))->toBe(['item_status' => ItemStatus::Rejected, 'rejection_reason' => 'Add the pickup time.']);
});

test('a listing another manager already decided is refused with its status', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    tenancy()->initialize($tenant);
    $item = Item::factory()->pending()->create(['name' => 'Apo Island Snorkel']);

    $page = listingApprovals($tenant, User::factory()->role(Role::Manager)->for($tenant)->create())->call('confirmApprove', $item->id);
    $item->update(['item_status' => ItemStatus::Rejected]);

    $page->call('approve')->assertHasErrors(['status' => 'Apo Island Snorkel is rejected, so it cannot be approved.']);
});

test('without Catalog in its plan the page says so and offers no decision', function () {
    $tenant = tenantOnDomain(tier: null);
    tenancy()->initialize($tenant);
    $item = Item::factory()->pending()->create();

    listingApprovals($tenant, User::factory()->role(Role::Manager)->for($tenant)->create())
        ->assertSee('You can view these records, but not add or change them.')
        ->assertDontSeeHtml("confirmApprove({$item->id})")
        ->call('confirmApprove', $item->id)
        ->assertForbidden();
});
