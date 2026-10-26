<?php

use App\Enums\AuditAction;
use App\Enums\PlanFeature;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\PlanFeatureSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travel;

beforeEach(function () {
    // The detail page reads only central tables, so the per-tenant database
    // provisioning that TenantCreated triggers is skipped.
    Event::fake([TenantCreated::class]);
});

function tenantDetail(Tenant $tenant, ?User $user = null): Testable
{
    return Livewire::actingAs($user ?? User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.show', ['tenant' => $tenant]);
}

test('the detail page shows the agency\'s users, subscription status and audit trail', function () {
    $superAdmin = User::factory()->superAdmin()->create(['first_name' => 'Marisol', 'middle_name' => null, 'last_name' => 'Vega']);
    $tenant = Tenant::factory()->create(['name' => 'Dumaguete City Travel & Tours']);
    User::factory()->role(Role::Manager)->for($tenant)->create(['first_name' => 'Ignacio', 'middle_name' => null, 'last_name' => 'Ramos']);
    Subscription::factory()->for($tenant)->create(['plan_name' => 'Agency Standard', 'status' => SubscriptionStatus::PastDue]);
    app(AuditRecorder::class)->recordPlatformEvent(AuditAction::TenantCreated, $superAdmin, $tenant);

    actingAs($superAdmin)
        ->get(route('admin.tenants.show', $tenant))
        ->assertOk()
        ->assertSee('Dumaguete City Travel &amp; Tours', escape: false)
        ->assertSeeInOrder(['Ignacio Ramos', 'Manager'])
        ->assertSeeInOrder(['Agency Standard', 'Past due'])
        ->assertSeeInOrder(['Tenant Created', 'Marisol Vega (Super admin)']);
});

test('the subscription card shows the tier and the features the agency has now', function () {
    $tenant = Tenant::factory()->create();
    Subscription::factory()->for($tenant)->create(['plan_name' => 'Pro Monthly', 'tier' => PlanTier::Pro]);
    PlanFeatureSetting::factory()->create(['feature' => PlanFeature::Analytics, 'minimum_tier' => PlanTier::Advanced]);

    $detail = tenantDetail($tenant)->assertSeeInOrder(['Pro Monthly', 'Pro', 'Features the agency has now', 'Catalog', 'Analytics', 'Priority restore requests']);

    expect($detail->instance()->features)->toBe(PlanFeature::cases());
});

test('an agency whose subscription has ended has no plan features', function () {
    $tenant = Tenant::factory()->create();
    Subscription::factory()->for($tenant)->expired()->create(['tier' => PlanTier::Pro]);

    $detail = tenantDetail($tenant)->assertSee('None: the agency has no live plan.');

    expect($detail->instance()->features)->toBe([]);
});

test('the detail page reads only that agency\'s users, subscription and audit events', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $ownUser = User::factory()->role(Role::Admin)->for($tenant)->create();
    User::factory()->role(Role::Admin)->for($other)->create();
    $ownSubscription = Subscription::factory()->for($tenant)->create();
    Subscription::factory()->for($other)->create();
    $ownEvent = app(AuditRecorder::class)->recordPlatformEvent(AuditAction::TenantCreated, $superAdmin, $tenant);
    app(AuditRecorder::class)->recordPlatformEvent(AuditAction::TenantCreated, $superAdmin, $other);
    app(AuditRecorder::class)->recordPlatformEvent(AuditAction::AuthLogin, $superAdmin);

    $detail = tenantDetail($tenant, $superAdmin)->instance();

    expect($detail->users->modelKeys())->toBe([$ownUser->id])
        ->and($detail->subscription?->id)->toBe($ownSubscription->id)
        ->and($detail->recentActivity->modelKeys())->toBe([$ownEvent->id]);
});

test('the detail page lists the agency\'s staff but never its customers', function () {
    $tenant = Tenant::factory()->create();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $deletedStaff = User::factory()->role(Role::Staff)->for($tenant)->create(['deleted_at' => now()]);
    User::factory()->role(Role::Customer)->for($tenant)->create();
    User::factory()->role(Role::Customer)->for($tenant)->create(['deleted_at' => now()]);

    $detail = tenantDetail($tenant)->instance();

    expect($detail->users->modelKeys())->toBe([$manager->id])
        ->and($detail->promotableUsers->modelKeys())->toBe([$manager->id])
        ->and(collect($detail->deletedRecords)->pluck('id')->all())->toBe([$deletedStaff->id]);
});

test('the subscription shown is the live one rather than an older ended one', function () {
    $tenant = Tenant::factory()->create();
    Subscription::factory()->for($tenant)->expired()->create();
    $live = Subscription::factory()->for($tenant)->create();

    expect(tenantDetail($tenant)->instance()->subscription?->id)->toBe($live->id);
});

test('an agency that has never subscribed says so', function () {
    tenantDetail(Tenant::factory()->create())
        ->assertSee('This agency has never had a subscription.');
});

test('recent activity lists only the ten newest events', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $tenant = Tenant::factory()->create();
    $events = collect(range(1, 11))->map(function () use ($superAdmin, $tenant) {
        travel(1)->minute();

        return app(AuditRecorder::class)->recordPlatformEvent(AuditAction::TenantCreated, $superAdmin, $tenant);
    });

    expect(tenantDetail($tenant, $superAdmin)->instance()->recentActivity->modelKeys())
        ->toBe($events->reverse()->take(10)->pluck('id')->all());
});

test('an unknown agency is not found', function () {
    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.tenants.show', 'no-such-agency'))
        ->assertNotFound();
});

test('the detail page is forbidden to the agency\'s own admin', function () {
    $tenant = Tenant::factory()->create();

    actingAs(User::factory()->role(Role::Admin)->for($tenant)->create())
        ->get(route('admin.tenants.show', $tenant))
        ->assertForbidden();
});

test('the detail component refuses non-super-admins without the console middleware', function () {
    $tenant = Tenant::factory()->create();

    tenantDetail($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())->assertForbidden();
});
