<?php

use App\Enums\AuditAction;
use App\Enums\PlanFeature;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\PlanFeatureSetting;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    // Plan features and subscriptions live in the central database, so the
    // per-tenant database provisioning that TenantCreated triggers is skipped.
    Event::fake([TenantCreated::class]);
});

function featuresPage(?User $user = null): Testable
{
    return Livewire::actingAs($user ?? User::factory()->superAdmin()->create())->test('pages::admin.plans.index');
}

test('every feature starts at its default tier until the super admin moves it', function () {
    PlanFeatureSetting::factory()->create(['feature' => PlanFeature::Reviews, 'minimum_tier' => PlanTier::Pro]);

    $page = featuresPage()->assertSeeInOrder(['Reviews & wishlist', 'Moved from Advanced']);
    $features = $page->instance()->features;

    expect($features)->toHaveCount(count(PlanFeature::cases()))
        ->and($features[PlanFeature::Reviews->value])->toBe(PlanTier::Pro)
        ->and($features[PlanFeature::StripePayments->value])->toBe(PlanTier::Advanced)
        ->and($features[PlanFeature::Catalog->value])->toBe(PlanTier::Basic);
});

test('moving a feature up says how many live agencies lose it, needs a reason, and is recorded', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $advanced = tenantOnDomain(tier: PlanTier::Advanced);
    $pro = tenantOnDomain(tier: PlanTier::Pro);
    tenantOnDomain(tier: PlanTier::Basic);
    Subscription::factory()->pastDue()->create(['tier' => PlanTier::Advanced]);
    Subscription::factory()->expired()->create(['tier' => PlanTier::Advanced]);

    $page = featuresPage($superAdmin)
        ->call('moveFeature', PlanFeature::Reviews->value)
        ->assertSet('minimum_tier', PlanTier::Advanced->value)
        ->set('minimum_tier', PlanTier::Pro->value)
        ->assertSee('2 live agencies lose it.')
        ->call('saveFeatureMove')
        ->assertHasErrors(['move_reason' => 'Say why agencies on a lower tier lose this feature. The reason is kept in the audit log.']);

    expect(PlanFeatureSetting::count())->toBe(0)
        ->and(PlatformAuditEvent::count())->toBe(0);

    $page->set('move_reason', ' Reviews are a Pro perk now. ')->call('saveFeatureMove')->assertHasNoErrors();

    $setting = PlanFeatureSetting::sole();
    expect($setting->feature)->toBe(PlanFeature::Reviews)
        ->and($setting->minimum_tier)->toBe(PlanTier::Pro)
        ->and($setting->updated_by)->toBe($superAdmin->id)
        ->and($advanced->fresh()?->hasFeature(PlanFeature::Reviews))->toBeFalse()
        ->and($pro->fresh()?->hasFeature(PlanFeature::Reviews))->toBeTrue();

    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::PlanFeatureMoved)
        ->and($event->tenant_id)->toBeNull()
        ->and($event->actor_id)->toBe($superAdmin->id)
        ->and($event->subject_type)->toBe('plan_feature')
        ->and($event->subject_id)->toBe($setting->id)
        ->and($event->description)->toBe('Reviews are a Pro perk now.')
        ->and($event->changes)->toBe(['minimum_tier' => ['old' => PlanTier::Advanced->value, 'new' => PlanTier::Pro->value]])
        ->and($event->context)->toBe(['feature' => 'reviews', 'agencies_losing' => 2]);
});

test('moving a feature down takes it from nobody, needs no reason, and is recorded', function () {
    $basic = tenantOnDomain(tier: PlanTier::Basic);

    featuresPage()
        ->call('moveFeature', PlanFeature::CsvExport->value)
        ->set('minimum_tier', PlanTier::Basic->value)
        ->assertDontSee('lose it')
        ->call('saveFeatureMove')
        ->assertHasNoErrors();

    expect($basic->fresh()?->hasFeature(PlanFeature::CsvExport))->toBeTrue();

    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::PlanFeatureMoved)
        ->and($event->description)->toBeNull()
        ->and($event->changes)->toBe(['minimum_tier' => ['old' => PlanTier::Advanced->value, 'new' => PlanTier::Basic->value]])
        ->and($event->context)->toBe(['feature' => 'csv_export', 'agencies_losing' => 0]);
});

test('moving a feature to the tier it already starts at records nothing', function () {
    featuresPage()
        ->call('moveFeature', PlanFeature::Analytics->value)
        ->call('saveFeatureMove')
        ->assertHasNoErrors();

    expect(PlanFeatureSetting::withTrashed()->count())->toBe(0)
        ->and(PlatformAuditEvent::count())->toBe(0);
});

test('moving a feature reuses its soft-deleted override rather than adding a second one', function () {
    $setting = PlanFeatureSetting::factory()->create(['feature' => PlanFeature::Reviews, 'minimum_tier' => PlanTier::Basic]);
    $setting->delete();

    $page = featuresPage();
    expect($page->instance()->features[PlanFeature::Reviews->value])->toBe(PlanTier::Advanced);

    $page->call('moveFeature', PlanFeature::Reviews->value)
        ->set(['minimum_tier' => PlanTier::Pro->value, 'move_reason' => 'Pro perk.'])
        ->call('saveFeatureMove')
        ->assertHasNoErrors();

    expect(PlanFeatureSetting::withTrashed()->count())->toBe(1)
        ->and($setting->fresh()?->trashed())->toBeFalse()
        ->and($setting->fresh()?->minimum_tier)->toBe(PlanTier::Pro);
});

test('an unknown tier is rejected and nothing is moved', function () {
    featuresPage()
        ->call('moveFeature', PlanFeature::Reviews->value)
        ->set(['minimum_tier' => 9, 'move_reason' => 'Because.'])
        ->call('saveFeatureMove')
        ->assertHasErrors(['minimum_tier' => 'Choose the tier the feature starts at.']);

    expect(PlanFeatureSetting::count())->toBe(0)
        ->and(PlatformAuditEvent::count())->toBe(0);
});

test('every write on the feature matrix is refused once the user is no longer a super admin', function (string $action, array $arguments) {
    // Opened by a super admin, then the session changes hands, as in a stale
    // tab: each action must authorize for itself.
    $page = featuresPage()
        ->call('moveFeature', PlanFeature::Reviews->value)
        ->set(['minimum_tier' => PlanTier::Basic->value]);
    actingAs(User::factory()->role(Role::Admin)->for(Tenant::factory())->create());

    $page->call($action, ...$arguments)->assertForbidden();

    expect(PlanFeatureSetting::count())->toBe(0)
        ->and(PlatformAuditEvent::count())->toBe(0);
})->with([
    'opening the move form' => ['moveFeature', [PlanFeature::Reviews->value]],
    'moving the feature' => ['saveFeatureMove', []],
]);

test('an agency\'s tier is its live subscription\'s: trialing and past due keep the features, an ended one loses them', function (Closure $subscribe, ?PlanTier $tier) {
    $tenant = Tenant::factory()->create();
    $subscribe($tenant);

    expect($tenant->currentTier())->toBe($tier)
        ->and($tenant->hasFeature(PlanFeature::Reviews))->toBe($tier !== null);
})->with([
    'trialing' => [fn (Tenant $tenant) => Subscription::factory()->for($tenant)->create(['tier' => PlanTier::Advanced, 'status' => SubscriptionStatus::Trialing]), PlanTier::Advanced],
    'active' => [fn (Tenant $tenant) => Subscription::factory()->for($tenant)->create(['tier' => PlanTier::Advanced]), PlanTier::Advanced],
    'past due' => [fn (Tenant $tenant) => Subscription::factory()->for($tenant)->pastDue()->create(['tier' => PlanTier::Advanced]), PlanTier::Advanced],
    'cancelled' => [fn (Tenant $tenant) => Subscription::factory()->for($tenant)->cancelled()->create(['tier' => PlanTier::Advanced]), null],
    'expired' => [fn (Tenant $tenant) => Subscription::factory()->for($tenant)->expired()->create(['tier' => PlanTier::Advanced]), null],
    'never subscribed' => [fn () => null, null],
]);

test('an agency has exactly the features its tier includes, in order', function () {
    PlanFeatureSetting::factory()->create(['feature' => PlanFeature::AuditLog, 'minimum_tier' => PlanTier::Advanced]);

    expect(tenantOnDomain(tier: PlanTier::Basic)->features())->toBe([
        PlanFeature::Catalog,
        PlanFeature::Inventory,
        PlanFeature::Bookings,
        PlanFeature::BrandedApp,
        PlanFeature::Notifications,
        PlanFeature::Team,
        PlanFeature::QrCheckIn,
    ]);
});

test('QR check-in starts at Basic and full white-label at Advanced', function () {
    expect(tenantOnDomain(tier: PlanTier::Basic)->hasFeature(PlanFeature::QrCheckIn))->toBeTrue()
        ->and(tenantOnDomain(tier: PlanTier::Basic)->hasFeature(PlanFeature::WhiteLabel))->toBeFalse()
        ->and(tenantOnDomain(tier: PlanTier::Advanced)->hasFeature(PlanFeature::WhiteLabel))->toBeTrue();
});
