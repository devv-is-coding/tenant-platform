<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\PlatformAuditEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    // Agency users live in the central database, so no tenant database is provisioned.
    Event::fake([TenantCreated::class]);
});

function agencyAdminsPage(Tenant $tenant, ?User $superAdmin = null): Testable
{
    return Livewire::actingAs($superAdmin ?? User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.show', ['tenant' => $tenant]);
}

test('a super admin creates a new admin account for the agency, and it is recorded', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $tenant = Tenant::factory()->create();

    agencyAdminsPage($tenant, $superAdmin)
        ->call('openAssignAdmin')
        ->set(['first_name' => 'Rosa', 'last_name' => 'Lim', 'username' => 'dgte_admin2', 'email' => 'rosa@example.com', 'password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026'])
        ->call('assignAdmin')
        ->assertHasNoErrors();

    $admin = User::query()->where('username', 'dgte_admin2')->sole();
    expect($admin->role)->toBe(Role::Admin)
        ->and($admin->tenant_id)->toBe($tenant->id)
        ->and($admin->is_active)->toBeTrue();

    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::TenantAdminAssigned)
        ->and($event->tenant_id)->toBe($tenant->id)
        ->and($event->actor_id)->toBe($superAdmin->id)
        ->and($event->subject_type)->toBe('user')
        ->and($event->subject_id)->toBe($admin->id)
        ->and($event->context)->toBe(['via' => 'created']);
});

test('a new admin account needs details no other account uses', function () {
    $tenant = Tenant::factory()->create();
    User::factory()->create(['email' => 'taken@example.com']);

    agencyAdminsPage($tenant)
        ->call('openAssignAdmin')
        ->set(['first_name' => 'Rosa', 'last_name' => 'Lim', 'username' => 'dgte_admin2', 'email' => 'taken@example.com', 'password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026'])
        ->call('assignAdmin')
        ->assertHasErrors(['email' => 'The email has already been taken.']);

    expect(User::query()->where('username', 'dgte_admin2')->exists())->toBeFalse()
        ->and(PlatformAuditEvent::count())->toBe(0);
});

test('a super admin promotes one of the agency\'s managers or staff to admin', function (Role $role) {
    $tenant = Tenant::factory()->create();
    $promoted = User::factory()->role($role)->for($tenant)->create();

    agencyAdminsPage($tenant)
        ->call('openAssignAdmin')
        ->set('assign_mode', 'promote')
        ->set('user_id', $promoted->id)
        ->call('assignAdmin')
        ->assertHasNoErrors();

    expect($promoted->fresh()?->role)->toBe(Role::Admin);

    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::TenantAdminAssigned)
        ->and($event->subject_id)->toBe($promoted->id)
        ->and($event->changes)->toBe(['role' => ['old' => $role->value, 'new' => Role::Admin->value]])
        ->and($event->context)->toBe(['via' => 'promoted']);
})->with([
    'manager' => Role::Manager,
    'staff' => Role::Staff,
]);

test('only an active manager or staff member of this agency can be promoted', function (Closure $candidate) {
    $tenant = Tenant::factory()->create();
    $user = $candidate($tenant);
    $roleBefore = $user->role;

    agencyAdminsPage($tenant)
        ->call('openAssignAdmin')
        ->set('assign_mode', 'promote')
        ->set('user_id', $user->id)
        ->call('assignAdmin')
        ->assertHasErrors(['user_id' => 'Choose one of the agency\'s active managers or staff.']);

    expect($user->fresh()?->role)->toBe($roleBefore)
        ->and(PlatformAuditEvent::count())->toBe(0);
})->with([
    'another agency\'s staff' => [fn () => User::factory()->role(Role::Staff)->for(Tenant::factory())->create()],
    'a deactivated staff member' => [fn (Tenant $tenant) => User::factory()->role(Role::Staff)->for($tenant)->create(['is_active' => false])],
    'a customer' => [fn () => User::factory()->role(Role::Customer)->create()],
    'a super admin' => [fn () => User::factory()->superAdmin()->create()],
]);

test('revoking an admin deactivates and soft-deletes the account but keeps its agency', function () {
    $tenant = Tenant::factory()->create();
    User::factory()->role(Role::Admin)->for($tenant)->create();
    $leaving = User::factory()->role(Role::Admin)->for($tenant)->create();

    agencyAdminsPage($tenant)
        ->call('confirmRevoke', $leaving->id)
        ->set('revoke_reason', 'Left the agency.')
        ->call('revokeAdmin')
        ->assertHasNoErrors();

    $revoked = User::withTrashed()->find($leaving->id);
    expect($revoked?->trashed())->toBeTrue()
        ->and($revoked?->is_active)->toBeFalse()
        ->and($revoked?->tenant_id)->toBe($tenant->id)
        ->and($revoked?->role)->toBe(Role::Admin);

    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::TenantAdminRevoked)
        ->and($event->subject_id)->toBe($leaving->id)
        ->and($event->description)->toBe('Left the agency.');
});

test('revoking needs a reason', function () {
    $tenant = Tenant::factory()->create();
    User::factory()->role(Role::Admin)->for($tenant)->create();
    $leaving = User::factory()->role(Role::Admin)->for($tenant)->create();

    agencyAdminsPage($tenant)
        ->call('confirmRevoke', $leaving->id)
        ->call('revokeAdmin')
        ->assertHasErrors(['revoke_reason' => 'Say why this admin\'s access is revoked. The reason is kept in the audit log.']);

    expect($leaving->fresh()?->trashed())->toBeFalse();
});

test('the agency\'s only active admin can be neither revoked nor deleted', function (string $how) {
    $tenant = Tenant::factory()->create();
    $only = User::factory()->role(Role::Admin)->for($tenant)->create(['first_name' => 'Rafa', 'middle_name' => null, 'last_name' => 'Benedicto']);
    // A deactivated admin does not govern the agency, so it does not count.
    User::factory()->role(Role::Admin)->for($tenant)->create(['is_active' => false]);

    $page = agencyAdminsPage($tenant);
    $page = $how === 'revoke'
        ? $page->call('confirmRevoke', $only->id)->set('revoke_reason', 'Left.')->call('revokeAdmin')
        : $page->call('confirmDelete', 'user', $only->id)->set('delete_reason', 'Left.')->call('deleteRecord');

    $page->assertHasErrors(['admin' => 'Rafa Benedicto is the agency\'s only active admin. Assign another admin first.']);

    expect($only->fresh()?->trashed())->toBeFalse()
        ->and($only->fresh()?->is_active)->toBeTrue()
        ->and(PlatformAuditEvent::count())->toBe(0);
})->with(['revoke', 'delete']);

test('deleting staff is unaffected by the last-admin guard', function () {
    $tenant = Tenant::factory()->create();
    User::factory()->role(Role::Admin)->for($tenant)->create();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();

    agencyAdminsPage($tenant)
        ->call('confirmDelete', 'user', $staff->id)
        ->set('delete_reason', 'Left.')
        ->call('deleteRecord')
        ->assertHasNoErrors();

    expect($staff->fresh()?->trashed())->toBeTrue();
});

test('assigning or revoking an admin is refused once the user is no longer a super admin', function (string $action) {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    User::factory()->role(Role::Admin)->for($tenant)->create();
    $page = agencyAdminsPage($tenant)
        ->set(['first_name' => 'Rosa', 'last_name' => 'Lim', 'username' => 'hijack', 'email' => 'hijack@example.com', 'password' => 'Str0ng!Passw0rd#2026']);
    actingAs($admin);

    $page->call($action, ...($action === 'confirmRevoke' ? [$admin->id] : []))->assertForbidden();

    expect(User::query()->where('username', 'hijack')->exists())->toBeFalse()
        ->and($admin->fresh()?->trashed())->toBeFalse();
})->with(['openAssignAdmin', 'assignAdmin', 'confirmRevoke']);
