<?php

use App\Enums\PlanTier;
use App\Enums\RestoreRequestStatus;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\RestoreRequest;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Open the agency's restore requests page as the user, from within the agency.
 */
function agencyRestoreRequests(Tenant $tenant, User $user): Testable
{
    tenancy()->initialize($tenant);

    return Livewire::actingAs($user)->test('pages::tenant.restore-requests');
}

/**
 * A user of the agency in the role, already deleted.
 */
function deletedUserOf(Tenant $tenant, string $firstName, Role $role = Role::Staff): User
{
    return tap(User::factory()->role($role)->for($tenant)->create(['first_name' => $firstName, 'middle_name' => null, 'last_name' => 'Deleted']))->delete();
}

test('the admin opens the page on the agency\'s domain; a manager is refused', function () {
    $tenant = tenantOnDomain();

    actingAs(User::factory()->role(Role::Admin)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/restore-requests")
        ->assertOk()
        ->assertSee('Restore requests');

    tenancy()->end();

    actingAs(User::factory()->role(Role::Manager)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/restore-requests")
        ->assertForbidden();
});

test('the agency\'s deleted staff and billing records are listed, never its customers, its live records or another agency\'s', function () {
    $tenant = tenantOnDomain();
    $other = tenantOnDomain();
    deletedUserOf($tenant, 'Lorna');
    deletedUserOf($tenant, 'Beatriz', Role::Customer);
    deletedUserOf($other, 'Outsider');
    User::factory()->role(Role::Staff)->for($tenant)->create(['first_name' => 'Still', 'last_name' => 'Here']);
    $invoice = tap(SubscriptionInvoice::factory()->for(Subscription::factory()->for($tenant))->create(['reference' => 'PLAT-DELETED-1']))->delete();

    $page = agencyRestoreRequests($tenant, User::factory()->role(Role::Admin)->for($tenant)->create());

    expect(collect($page->instance()->deletedRecords)->pluck('name')->all())->toEqualCanonicalizing(['Lorna Deleted', $invoice->reference]);
    $page->assertDontSee('Beatriz')->assertDontSee('Outsider')->assertDontSee('Still Here');
});

test('the admin asks for a deleted record back with a reason, and it shows as requested', function () {
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $staff = deletedUserOf($tenant, 'Lorna');

    $page = agencyRestoreRequests($tenant, $admin)
        ->call('confirmRequest', 'user', $staff->id)
        ->set('reason', 'Deleted the wrong account.')
        ->call('sendRequest')
        ->assertHasNoErrors();

    $request = RestoreRequest::sole();

    expect($request->tenant_id)->toBe($tenant->id)
        ->and($request->restorable_id)->toBe($staff->id)
        ->and($request->requested_by)->toBe($admin->id)
        ->and($request->status)->toBe(RestoreRequestStatus::Pending)
        ->and($page->instance()->deletedRecords[0]['pending'])->toBeTrue();

    $page->assertSeeInOrder(['Lorna Deleted', 'Requested']);
});

test('a request needs a reason', function () {
    $tenant = tenantOnDomain();
    $staff = deletedUserOf($tenant, 'Lorna');

    agencyRestoreRequests($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->call('confirmRequest', 'user', $staff->id)
        ->call('sendRequest')
        ->assertHasErrors(['reason' => 'Say why this should be restored.']);

    expect(RestoreRequest::count())->toBe(0);
});

test('a second request for a record already pending is refused', function () {
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $staff = deletedUserOf($tenant, 'Lorna');
    RestoreRequest::factory()->for($tenant)->create(['restorable_id' => $staff->id, 'requested_by' => $admin->id]);

    agencyRestoreRequests($tenant, $admin)
        ->call('confirmRequest', 'user', $staff->id)
        ->set('reason', 'Again.')
        ->call('sendRequest')
        ->assertHasErrors(['reason' => 'A restore of this record has already been requested.']);

    expect(RestoreRequest::count())->toBe(1);
});

test('another agency\'s deleted record, or one of the agency\'s customers, cannot be requested: it is not found', function (Closure $makeRecord) {
    $tenant = tenantOnDomain();

    agencyRestoreRequests($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->call('confirmRequest', 'user', $makeRecord($tenant)->id)
        ->assertNotFound();
})->with([
    'another agency\'s deleted staff' => [fn () => deletedUserOf(tenantOnDomain(), 'Outsider')],
    'the agency\'s deleted customer' => [fn (Tenant $tenant) => deletedUserOf($tenant, 'Beatriz', Role::Customer)],
]);

test('the admin sees each request\'s outcome, a declined one with the platform\'s reason, and never another agency\'s', function () {
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    RestoreRequest::factory()->for($tenant)->create(['reason' => 'pending-one']);
    RestoreRequest::factory()->for($tenant)->create(['reason' => 'restored-one', 'status' => RestoreRequestStatus::Restored, 'decided_at' => now()]);
    RestoreRequest::factory()->for($tenant)->declined()->create(['reason' => 'declined-one', 'decision_reason' => 'Deleted on purpose by the owner.']);
    RestoreRequest::factory()->for(tenantOnDomain())->create(['reason' => 'theirs']);

    agencyRestoreRequests($tenant, $admin)
        ->assertSeeInOrder(['declined-one', 'Declined', 'Reason: Deleted on purpose by the owner.', 'restored-one', 'Restored', 'pending-one', 'Pending'])
        ->assertDontSee('theirs');
});

test('a closed agency with no plan can still ask for its records back', function () {
    $tenant = tenantOnDomain(TenantStatus::Closed, tier: null);
    $staff = deletedUserOf($tenant, 'Lorna');

    agencyRestoreRequests($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->call('confirmRequest', 'user', $staff->id)
        ->set('reason', 'We need their history.')
        ->call('sendRequest')
        ->assertHasNoErrors();

    expect(RestoreRequest::count())->toBe(1);
});

test('the page component refuses anyone but the agency\'s admin, without the route middleware', function (Closure $makeUser) {
    $tenant = tenantOnDomain(tier: PlanTier::Pro);

    agencyRestoreRequests($tenant, $makeUser($tenant))->assertForbidden();
})->with([
    'its staff' => [fn (Tenant $tenant) => User::factory()->role(Role::Staff)->for($tenant)->create()],
    'another agency\'s admin' => [fn () => User::factory()->role(Role::Admin)->for(tenantOnDomain())->create()],
]);

test('text the admin and the platform wrote is escaped when shown', function () {
    $tenant = tenantOnDomain();
    RestoreRequest::factory()->for($tenant)->declined()->create(['reason' => '<script>alert(1)</script>', 'decision_reason' => '<b>no</b>']);

    agencyRestoreRequests($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->assertSee('&lt;script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false)
        ->assertDontSee('<b>no</b>', escape: false);
});
