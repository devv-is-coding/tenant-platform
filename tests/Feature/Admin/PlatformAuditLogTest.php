<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\PlatformAuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    // The log is central, so no tenant database is provisioned.
    Event::fake([TenantCreated::class]);
});

function auditLogPage(?User $superAdmin = null): Testable
{
    return Livewire::actingAs($superAdmin ?? User::factory()->superAdmin()->create())->test('pages::admin.audit.index');
}

/**
 * Record a platform event as of the given moment, through the same recorder
 * the application uses.
 *
 * @param  array<string, mixed>|null  $changes
 */
function recordPlatformEventOn(string $moment, AuditAction $action, ?User $actor, ?Tenant $tenant, string $description, ?array $changes = null): PlatformAuditEvent
{
    travelTo($moment);

    return app(AuditRecorder::class)->recordPlatformEvent($action, $actor, $tenant, $description, $changes);
}

/**
 * Four events across two agencies, two super admins and the system, ten days
 * apart, each marked by its description.
 */
function seedAuditTrail(): void
{
    $marisol = User::factory()->superAdmin()->create(['id' => 101, 'first_name' => 'Marisol', 'middle_name' => null, 'last_name' => 'Vega']);
    $jun = User::factory()->superAdmin()->create(['id' => 102, 'first_name' => 'Jun', 'middle_name' => null, 'last_name' => 'Reyes']);
    $dumaguete = Tenant::factory()->create(['id' => 'dumaguete', 'name' => 'Dumaguete Travel']);
    $tanjay = Tenant::factory()->create(['id' => 'tanjay', 'name' => 'Tanjay Travel']);

    recordPlatformEventOn('2026-10-01 09:00:00', AuditAction::TenantCreated, $marisol, $dumaguete, 'event-1');
    recordPlatformEventOn('2026-10-10 09:00:00', AuditAction::SubscriptionCreated, $marisol, $tanjay, 'event-2');
    recordPlatformEventOn('2026-10-15 23:59:00', AuditAction::SubscriptionMarkedPastDue, null, $dumaguete, 'event-3', ['status' => ['old' => 2, 'new' => 3]]);
    recordPlatformEventOn('2026-10-20 09:00:00', AuditAction::AuthLogin, $jun, null, 'event-4');
}

/**
 * @return list<string|null>
 */
function listedDescriptions(Testable $page): array
{
    return $page->instance()->events->pluck('description')->all();
}

test('the log lists every platform event newest first, with who did what to which agency', function () {
    seedAuditTrail();
    travelTo('2026-10-21 09:00:00');
    // The agency was deleted since, which nulls tenant_id but keeps the label.
    PlatformAuditEvent::create(['action' => AuditAction::TenantDeleted, 'tenant_label' => 'Zamboanguita Tours', 'description' => 'event-5']);

    $page = auditLogPage();

    expect(listedDescriptions($page))->toBe(['event-5', 'event-4', 'event-3', 'event-2', 'event-1']);
    $page->assertSeeInOrder([
        'Tenant Deleted', 'event-5', 'Zamboanguita Tours', 'Agency deleted', 'System',
        'Auth Login', 'event-4', 'Platform', 'Jun Reyes (Super admin)',
        'Subscription Past Due', 'event-3', 'status: 2 → 3', 'Dumaguete Travel', 'System',
        'Subscription Created', 'event-2', 'Tanjay Travel', 'Marisol Vega (Super admin)',
    ])->assertSeeHtml('href="'.route('admin.tenants.show', 'dumaguete').'"');
});

test('each filter narrows the log to the matching events', function (array $filters, array $expected) {
    seedAuditTrail();

    $page = auditLogPage()->set($filters);

    expect(listedDescriptions($page))->toBe($expected);
})->with([
    'one agency' => [['tenant' => 'dumaguete'], ['event-3', 'event-1']],
    'one super admin' => [['actor' => '101'], ['event-2', 'event-1']],
    'the system' => [['actor' => 'system'], ['event-3']],
    'one action' => [['action' => AuditAction::SubscriptionCreated->value], ['event-2']],
    'from a day' => [['from' => '2026-10-10'], ['event-4', 'event-3', 'event-2']],
    'to a day, including all of it' => [['to' => '2026-10-15'], ['event-3', 'event-2', 'event-1']],
    'a range' => [['from' => '2026-10-10', 'to' => '2026-10-15'], ['event-3', 'event-2']],
    'filters combined' => [['tenant' => 'dumaguete', 'actor' => '101'], ['event-1']],
    'searching the reason' => [['search' => 'event-2'], ['event-2']],
    'searching who did it, in any case' => [['search' => 'reyes'], ['event-4']],
    'searching the agency' => [['search' => 'Tanjay'], ['event-2']],
    'searching the IP address' => [['search' => '127.0.0.1'], ['event-4', 'event-3', 'event-2', 'event-1']],
    'searching with surrounding spaces' => [['search' => '  event-3  '], ['event-3']],
    'searching for something absent' => [['search' => 'refund'], []],
    'searching within a filter' => [['search' => 'Marisol', 'tenant' => 'tanjay'], ['event-2']],
]);

test('a filter value that does not parse is ignored rather than refused', function (array $filters) {
    seedAuditTrail();

    $page = auditLogPage()->set($filters)->assertHasNoErrors();

    expect(listedDescriptions($page))->toBe(['event-4', 'event-3', 'event-2', 'event-1']);
})->with([
    'a date that is not a date' => [['from' => 'yesterday']],
    'a date that does not exist' => [['to' => '2026-02-30']],
    'an action that does not exist' => [['action' => 'item.teleported']],
    'an actor that is not an id' => [['actor' => 'marisol']],
]);

test('filters are read from the query string, so a filtered view can be linked to', function () {
    seedAuditTrail();

    $page = Livewire::withQueryParams(['tenant' => 'tanjay'])
        ->actingAs(User::factory()->superAdmin()->create())
        ->test('pages::admin.audit.index');

    expect(listedDescriptions($page))->toBe(['event-2']);
});

test('the agency detail page links to the log filtered to that agency', function () {
    $tenant = Tenant::factory()->create(['id' => 'dumaguete']);

    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.show', ['tenant' => $tenant])
        ->assertSeeHtml('href="'.route('admin.audit.index', ['tenant' => 'dumaguete']).'"');
});

test('changing a filter returns to the first page', function () {
    $tenant = Tenant::factory()->create();
    // One more than a page holds.
    for ($recorded = 0; $recorded < 26; $recorded++) {
        app(AuditRecorder::class)->recordPlatformEvent(AuditAction::TenantCreated, null, $tenant);
    }

    auditLogPage()
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('tenant', $tenant->id)
        ->assertSet('paginators.page', 1);
});

test('clearing the filters shows the whole log again', function () {
    seedAuditTrail();

    $page = auditLogPage()
        ->set(['search' => 'event', 'tenant' => 'dumaguete', 'actor' => 'system', 'action' => AuditAction::SubscriptionMarkedPastDue->value, 'from' => '2026-10-01', 'to' => '2026-10-31'])
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('tenant', '')
        ->assertSet('actor', '')
        ->assertSet('action', '')
        ->assertSet('from', '')
        ->assertSet('to', '');

    expect(listedDescriptions($page))->toHaveCount(4);
});

test('the actor filter offers everyone in the log under their latest name, including deleted users', function () {
    $marisol = User::factory()->superAdmin()->create(['id' => 101, 'first_name' => 'Marisol', 'middle_name' => null, 'last_name' => 'Vega']);
    $departed = User::factory()->superAdmin()->create(['id' => 102, 'first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Lim']);
    recordPlatformEventOn('2026-10-01 09:00:00', AuditAction::AuthLogin, $marisol, null, 'before the name change');
    recordPlatformEventOn('2026-10-02 09:00:00', AuditAction::AuthLogin, $departed, null, 'before leaving');
    $marisol->update(['last_name' => 'Cruz']);
    recordPlatformEventOn('2026-10-03 09:00:00', AuditAction::AuthLogin, $marisol, null, 'after the name change');
    $departed->delete();

    expect(auditLogPage()->instance()->actors->all())->toBe([
        102 => 'Ana Lim (Super admin)',
        101 => 'Marisol Cruz (Super admin)',
    ]);
});

test('the action filter offers only the actions that appear in the log', function () {
    seedAuditTrail();

    expect(auditLogPage()->instance()->actions)->toBe([
        AuditAction::AuthLogin,
        AuditAction::SubscriptionCreated,
        AuditAction::SubscriptionMarkedPastDue,
        AuditAction::TenantCreated,
    ]);
});

test('text recorded in the log is escaped when shown', function () {
    $actor = User::factory()->superAdmin()->create(['first_name' => '<b>Marisol</b>', 'middle_name' => null, 'last_name' => 'Vega']);
    $tenant = Tenant::factory()->create(['name' => '<img src=x onerror=alert(1)>']);
    app(AuditRecorder::class)->recordPlatformEvent(AuditAction::TenantSuspended, $actor, $tenant, '<script>alert(1)</script>');

    auditLogPage()
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertDontSeeHtml('<img src=x onerror=alert(1)>')
        ->assertDontSeeHtml('<b>Marisol</b>')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');
});

test('the audit log is forbidden to an agency\'s own admin', function () {
    actingAs(User::factory()->role(Role::Admin)->for(Tenant::factory())->create())
        ->get(route('admin.audit.index'))
        ->assertForbidden();
});

test('the audit log component refuses non-super-admins without the console middleware', function () {
    Livewire::actingAs(User::factory()->role(Role::Admin)->for(Tenant::factory())->create())
        ->test('pages::admin.audit.index')
        ->assertForbidden();
});
