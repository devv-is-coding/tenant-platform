<?php

use App\Enums\AuditAction;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\AuditEvent;
use App\Models\PlatformAuditEvent;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertGuest;

/**
 * Open the agency's team page as the user, from within the agency.
 */
function agencyTeam(Tenant $tenant, User $user): Testable
{
    tenancy()->initialize($tenant);

    return Livewire::actingAs($user)->test('pages::tenant.team');
}

/**
 * Fill the team page's form with a new member's details.
 */
function fillNewMember(Testable $page, string $role = '4'): Testable
{
    return $page->call('openCreate')
        ->set('first_name', 'Lorna')
        ->set('last_name', 'Villaruel')
        ->set('username', 'dgte_staff')
        ->set('email', 'lorna@example.test')
        ->set('role', $role)
        ->set('password', 'a-long-password')
        ->set('password_confirmation', 'a-long-password');
}

test('the admin opens the team page on the agency\'s domain; a manager is refused', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);

    actingAs(User::factory()->role(Role::Admin)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/team")
        ->assertOk()
        ->assertSee('Who works at');

    tenancy()->end();

    actingAs(User::factory()->role(Role::Manager)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/team")
        ->assertForbidden();
});

test('the team lists the agency\'s own staff, never its customers or another agency\'s staff', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create(['first_name' => 'Rafa']);
    User::factory()->role(Role::Manager)->for($tenant)->create(['first_name' => 'Marisol']);
    User::factory()->role(Role::Staff)->for($tenant)->inactive()->create(['first_name' => 'Dodong']);
    User::factory()->role(Role::Customer)->for($tenant)->create(['first_name' => 'Beatriz']);
    User::factory()->role(Role::Staff)->for(tenantOnDomain())->create(['first_name' => 'Outsider']);

    agencyTeam($tenant, $admin)
        ->assertSeeInOrder(['Rafa', 'Marisol', 'Dodong', 'Deactivated'])
        ->assertDontSee('Beatriz')
        ->assertDontSee('Outsider');
});

test('a member\'s name is escaped on the page', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    User::factory()->role(Role::Staff)->for($tenant)->create(['first_name' => '<script>alert(1)</script>']);

    agencyTeam($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->assertSee('&lt;script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

test('a new member lands centrally in this agency, active, with the chosen role, recorded in the agency\'s own log', function (string $role, Role $expected) {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();

    fillNewMember(agencyTeam($tenant, $admin), $role)
        ->call('saveMember')
        ->assertHasNoErrors();

    $member = User::query()->where('username', 'dgte_staff')->sole();
    $event = AuditEvent::query()->where('action', AuditAction::UserCreated)->sole();

    expect($member->getConnectionName())->toBe(config('tenancy.database.central_connection'))
        ->and($member->tenant_id)->toBe($tenant->id)
        ->and($member->role)->toBe($expected)
        ->and($member->is_active)->toBeTrue()
        ->and($event->actor_id)->toBe($admin->id)
        ->and($event->subject_type)->toBe('user')
        ->and($event->subject_id)->toBe($member->id)
        ->and(PlatformAuditEvent::query()->where('action', AuditAction::UserCreated)->exists())->toBeFalse();
})->with([
    'a manager' => ['2', Role::Manager],
    'staff' => ['4', Role::Staff],
]);

test('the role must be manager or staff: blank, admin, super admin and customer are refused', function (string $role, string $message) {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);

    fillNewMember(agencyTeam($tenant, User::factory()->role(Role::Admin)->for($tenant)->create()), $role)
        ->call('saveMember')
        ->assertHasErrors(['role' => $message]);

    expect(User::query()->where('username', 'dgte_staff')->exists())->toBeFalse();
})->with([
    'blank' => ['', 'Choose whether they are a manager or staff.'],
    'super admin (0)' => ['0', 'A team member is a manager or staff. Admins are assigned by the platform.'],
    'admin' => ['1', 'A team member is a manager or staff. Admins are assigned by the platform.'],
    'customer' => ['3', 'A team member is a manager or staff. Admins are assigned by the platform.'],
]);

test('a username already taken, even by a deleted account, is refused', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    User::factory()->role(Role::Staff)->for(tenantOnDomain())->create(['username' => 'dgte_staff'])->delete();

    fillNewMember(agencyTeam($tenant, User::factory()->role(Role::Admin)->for($tenant)->create()))
        ->call('saveMember')
        ->assertHasErrors(['username' => 'The username has already been taken.']);
});

test('editing moves a member between manager and staff and records what changed', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $member = User::factory()->role(Role::Staff)->for($tenant)->create(['last_name' => 'Cruz']);

    agencyTeam($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->call('openEdit', $member->id)
        ->assertSet('role', '4')
        ->set('role', '2')
        ->set('last_name', 'Dela Cruz')
        ->call('saveMember')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->where('action', AuditAction::UserUpdated)->sole();

    expect($member->fresh()->role)->toBe(Role::Manager)
        ->and($member->fresh()->last_name)->toBe('Dela Cruz')
        ->and($event->changes)->toBe([
            'last_name' => ['old' => 'Cruz', 'new' => 'Dela Cruz'],
            'role' => ['old' => 4, 'new' => 2],
        ]);
});

test('editing cannot promote a member to admin', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $member = User::factory()->role(Role::Manager)->for($tenant)->create();

    agencyTeam($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->call('openEdit', $member->id)
        ->set('role', '1')
        ->call('saveMember')
        ->assertHasErrors('role');

    expect($member->fresh()->role)->toBe(Role::Manager);
});

test('another agency\'s user or the agency\'s own customer is not found at all', function (Closure $makeUser, string $method, array $arguments) {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $target = $makeUser($tenant);

    agencyTeam($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->call($method, $target->id, ...$arguments)
        ->assertNotFound();

    expect($target->fresh()->is_active)->toBeTrue();
})->with([
    'another agency\'s staff' => [fn () => User::factory()->role(Role::Staff)->for(tenantOnDomain())->create()],
    'the agency\'s customer' => [fn (Tenant $tenant) => User::factory()->role(Role::Customer)->for($tenant)->create()],
])->with([
    'edit' => ['openEdit', []],
    'deactivate' => ['confirmAccess', [false]],
]);

test('another admin of the agency can be listed but not edited or deactivated here', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $otherAdmin = User::factory()->role(Role::Admin)->for($tenant)->create();

    agencyTeam($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->call('confirmAccess', $otherAdmin->id, false)
        ->assertForbidden();

    expect($otherAdmin->fresh()->is_active)->toBeTrue();
});

test('deactivating stops the member signing in and is recorded', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $member = User::factory()->role(Role::Staff)->for($tenant)->create();

    agencyTeam($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->call('confirmAccess', $member->id, false)
        ->call('changeAccess')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->where('action', AuditAction::UserDeactivated)->sole();

    expect($member->fresh()->is_active)->toBeFalse()
        ->and($member->fresh()->trashed())->toBeFalse()
        ->and($event->subject_id)->toBe($member->id)
        ->and($event->changes)->toBe(['is_active' => ['old' => true, 'new' => false]]);
});

test('a deactivated member already signed in is signed out on their next request', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);

    actingAs(User::factory()->role(Role::Staff)->for($tenant)->inactive()->create())
        ->get("http://{$tenant->id}.localhost/dashboard")
        ->assertRedirect(route('tenant.homepage'));

    assertGuest();
});

test('reactivating lets a member sign in again and is recorded', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $member = User::factory()->role(Role::Manager)->for($tenant)->inactive()->create();

    agencyTeam($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->call('confirmAccess', $member->id, true)
        ->call('changeAccess');

    expect($member->fresh()->is_active)->toBeTrue()
        ->and(AuditEvent::query()->where('action', AuditAction::UserReactivated)->count())->toBe(1);
});

test('with no live plan, adding a member is refused but deactivating one still works', function () {
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $member = User::factory()->role(Role::Staff)->for($tenant)->create();

    agencyTeam($tenant, $admin)
        ->assertSee('Available on Basic')
        ->assertDontSeeHtml('data-test="add-member-button"')
        ->call('openCreate')
        ->assertForbidden();

    agencyTeam($tenant, $admin)
        ->call('confirmAccess', $member->id, false)
        ->call('changeAccess');

    expect($member->fresh()->is_active)->toBeFalse();
});

test('a suspended agency\'s admin can still lock out a leaver, but not add anyone', function () {
    $tenant = tenantOnDomain(TenantStatus::Suspended, PlanTier::Pro);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $member = User::factory()->role(Role::Staff)->for($tenant)->create();

    agencyTeam($tenant, $admin)
        ->assertSee('You can still deactivate someone who has left')
        ->call('openCreate')
        ->assertForbidden();

    agencyTeam($tenant, $admin)
        ->call('confirmAccess', $member->id, false)
        ->call('changeAccess');

    expect($member->fresh()->is_active)->toBeFalse();
});
