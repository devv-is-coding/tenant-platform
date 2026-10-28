<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
 * The filter logic is the platform log's (PlatformAuditLogTest covers its
 * whole matrix); these tests cover what is the agency's own: whose events it
 * reads, who may read them, and one case of each filter.
 */

/**
 * Open the agency's audit log as the user, from within the agency.
 */
function agencyAuditLog(Tenant $tenant, User $user): Testable
{
    tenancy()->initialize($tenant);

    return Livewire::actingAs($user)->test('pages::tenant.audit');
}

/**
 * Record an agency event as of the given moment, through the same recorder
 * the application uses.
 */
function recordAgencyEventOn(Tenant $tenant, string $moment, AuditAction $action, ?User $actor, string $description): void
{
    travelTo($moment);

    withinTenant($tenant, fn () => app(AuditRecorder::class)->recordTenantEvent($action, $actor, $description));
}

/**
 * The agency's admin and three events of theirs, a staff member's and the
 * system's, plus the platform's suspension notice, each marked by its
 * description.
 */
function seedAgencyAuditTrail(Tenant $tenant): User
{
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create(['id' => 201, 'first_name' => 'Rafa', 'middle_name' => null, 'last_name' => 'Santos']);
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create(['id' => 202, 'first_name' => 'Lorna', 'middle_name' => null, 'last_name' => 'Cruz']);

    recordAgencyEventOn($tenant, '2026-10-01 09:00:00', AuditAction::SettingsUpdated, $admin, 'event-1');
    recordAgencyEventOn($tenant, '2026-10-10 09:00:00', AuditAction::UserCreated, $admin, 'event-2');
    recordAgencyEventOn($tenant, '2026-10-15 09:00:00', AuditAction::BookingCreated, $staff, 'event-3');
    recordAgencyEventOn($tenant, '2026-10-18 09:00:00', AuditAction::BookingExpired, null, 'event-4');

    travelTo('2026-10-20 09:00:00');
    app(AuditRecorder::class)->recordTenantLifecycleNotice(
        AuditAction::TenantSuspended,
        User::factory()->superAdmin()->create(['first_name' => 'Marisol', 'middle_name' => null, 'last_name' => 'Vega']),
        $tenant,
        'event-5',
    );

    return $admin;
}

/**
 * @return list<string|null>
 */
function listedAgencyDescriptions(Testable $page): array
{
    return $page->instance()->events->pluck('description')->all();
}

test('the admin opens the audit log on the agency\'s domain; a manager is refused', function () {
    $tenant = tenantOnDomain();

    actingAs(User::factory()->role(Role::Admin)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/audit")
        ->assertOk()
        ->assertSee('Audit log');

    tenancy()->end();

    actingAs(User::factory()->role(Role::Manager)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/audit")
        ->assertForbidden();
});

test('the log lists the agency\'s events newest first, the platform\'s notices among them', function () {
    $tenant = tenantOnDomain();
    $admin = seedAgencyAuditTrail($tenant);

    $page = agencyAuditLog($tenant, $admin);

    expect(listedAgencyDescriptions($page))->toBe(['event-5', 'event-4', 'event-3', 'event-2', 'event-1']);
    $page->assertSeeInOrder([
        'Tenant Suspended', 'Platform', 'event-5', 'Marisol Vega (Super admin)',
        'Booking Expired', 'event-4', 'System',
        'Booking Created', 'event-3', 'Lorna Cruz (Staff)',
    ]);
});

test('the log never shows another agency\'s events', function () {
    $tenant = tenantOnDomain();
    $other = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    recordAgencyEventOn($tenant, '2026-10-01 09:00:00', AuditAction::SettingsUpdated, $admin, 'ours');
    recordAgencyEventOn($other, '2026-10-02 09:00:00', AuditAction::SettingsUpdated, User::factory()->role(Role::Admin)->for($other)->create(), 'theirs');

    expect(listedAgencyDescriptions(agencyAuditLog($tenant, $admin)))->toBe(['ours']);
});

test('each filter narrows the log to the matching events', function (array $filters, array $expected) {
    $tenant = tenantOnDomain();
    $admin = seedAgencyAuditTrail($tenant);

    expect(listedAgencyDescriptions(agencyAuditLog($tenant, $admin)->set($filters)))->toBe($expected);
})->with([
    'one person' => [['actor' => '202'], ['event-3']],
    'the system' => [['actor' => 'system'], ['event-4']],
    'one action' => [['action' => AuditAction::UserCreated->value], ['event-2']],
    'a date range' => [['from' => '2026-10-10', 'to' => '2026-10-15'], ['event-3', 'event-2']],
    'searching who did it' => [['search' => 'lorna'], ['event-3']],
]);

test('a filter value that does not parse is ignored rather than refused', function () {
    $tenant = tenantOnDomain();
    $admin = seedAgencyAuditTrail($tenant);

    $page = agencyAuditLog($tenant, $admin)->set('from', 'yesterday')->assertHasNoErrors();

    expect(listedAgencyDescriptions($page))->toHaveCount(5);
});

test('a lapsed, closed agency\'s admin still reads its log', function () {
    $tenant = tenantOnDomain(TenantStatus::Closed);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    recordAgencyEventOn($tenant, '2026-10-01 09:00:00', AuditAction::SettingsUpdated, $admin, 'kept');

    expect(listedAgencyDescriptions(agencyAuditLog($tenant, $admin)))->toBe(['kept']);
});

test('text recorded in the log is escaped when shown', function () {
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    recordAgencyEventOn($tenant, '2026-10-01 09:00:00', AuditAction::SettingsUpdated, $admin, '<script>alert(1)</script>');

    agencyAuditLog($tenant, $admin)
        ->assertSee('&lt;script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

test('the audit log component refuses anyone but the agency\'s admin, without the route middleware', function (Closure $makeUser) {
    $tenant = tenantOnDomain();

    agencyAuditLog($tenant, $makeUser($tenant))->assertForbidden();
})->with([
    'its staff' => [fn (Tenant $tenant) => User::factory()->role(Role::Staff)->for($tenant)->create()],
    'another agency\'s admin' => [fn () => User::factory()->role(Role::Admin)->for(tenantOnDomain())->create()],
]);
