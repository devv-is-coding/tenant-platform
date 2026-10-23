<?php

use App\Actions\Tenants\ChangeTenantStatusAction;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\post;

beforeEach(function () {
    // The app has no tenant write routes yet, so the guard is exercised through
    // a probe on the same `tenant` middleware group every tenant route uses.
    Route::middleware('tenant')->post('/write-probe', fn () => 'written');
});

/**
 * Send a write as the given staff member to their agency's domain.
 *
 * Tenancy is ended first, as it would be at the start of a real request: the
 * test application keeps the previous request's tenant initialized, and
 * re-initializing the same tenant keeps that stale instance and its status.
 */
function writeAs(User $staff): TestResponse
{
    tenancy()->end();

    return actingAs($staff)->post("http://{$staff->tenant_id}.localhost/write-probe");
}

test('a restricted agency\'s staff are refused any write', function (TenantStatus $status) {
    $staff = User::factory()->role(Role::Staff)->for(tenantOnDomain($status))->create();

    writeAs($staff)
        ->assertForbidden()
        ->assertSee('This agency is '.mb_strtolower($status->label()).', so its records are read-only.');
})->with([
    'suspended' => TenantStatus::Suspended,
    'paused' => TenantStatus::Paused,
    'closed' => TenantStatus::Closed,
]);

test('a restricted agency\'s staff can still open their dashboard and are told why it is read-only', function () {
    $staff = User::factory()->role(Role::Staff)->for(tenantOnDomain(TenantStatus::Suspended))->create();

    actingAs($staff)
        ->get("http://{$staff->tenant_id}.localhost/dashboard")
        ->assertOk()
        ->assertSee('This agency is suspended. You can view its records but not change them.')
        ->assertSee('Reason: Subscription unpaid.');
});

test('a restricted agency\'s staff can still sign in', function () {
    $staff = User::factory()->role(Role::Staff)->for(tenantOnDomain(TenantStatus::Suspended))->create();

    post("http://{$staff->tenant_id}.localhost/login", ['email' => $staff->email, 'password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect("http://{$staff->tenant_id}.localhost/dashboard");

    assertAuthenticatedAs($staff);
});

test('suspending one agency makes only its staff read-only, and reactivating gives them writes back', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $agency = tenantOnDomain();
    $suspendedStaff = User::factory()->role(Role::Staff)->for($agency)->create();
    $otherStaff = User::factory()->role(Role::Staff)->for(tenantOnDomain())->create();
    $changeStatus = app(ChangeTenantStatusAction::class);

    $changeStatus->handle($superAdmin, $agency, TenantStatus::Suspended, ['status_reason' => 'Subscription unpaid.']);

    writeAs($suspendedStaff)->assertForbidden();
    writeAs($otherStaff)->assertOk()->assertSee('written');

    $changeStatus->handle($superAdmin, $agency, TenantStatus::Active, []);

    writeAs($suspendedStaff)->assertOk()->assertSee('written');
});
