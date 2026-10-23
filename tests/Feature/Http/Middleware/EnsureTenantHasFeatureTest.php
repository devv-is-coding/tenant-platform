<?php

use App\Enums\PlanFeature;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Http\Middleware\EnsureTenantHasFeature;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

beforeEach(function () {
    // No gated routes exist yet, so the gate is exercised through probes on
    // the stacks gated routes will use: the `tenant` group for the web, and
    // the agency-domain API stack CU-1 builds under /api/v1.
    Route::middleware(['tenant', EnsureTenantHasFeature::using(PlanFeature::Reviews)])->get('/reviews-probe', fn () => 'reviews');

    Route::middleware(['api', InitializeTenancyByDomain::class, PreventAccessFromCentralDomains::class, EnsureTenantHasFeature::using(PlanFeature::Reviews)])
        ->get('/api/v1/reviews-probe', fn () => ['data' => 'reviews']);
});

/**
 * Open the web probe as one of the agency's staff.
 *
 * Tenancy is ended first, as it would be at the start of a real request: the
 * test application keeps the previous request's tenant initialized.
 */
function openReviewsAs(Tenant $tenant): TestResponse
{
    tenancy()->end();
    actingAsTenantUser($tenant);

    return get("http://{$tenant->id}.localhost/reviews-probe");
}

function callReviewsApiOn(Tenant $tenant): TestResponse
{
    tenancy()->end();

    return getJson("http://{$tenant->id}.localhost/api/v1/reviews-probe");
}

test('an agency whose tier includes the feature gets through, on the web and the API', function (PlanTier $tier) {
    $tenant = tenantOnDomain(tier: $tier);

    openReviewsAs($tenant)->assertOk()->assertSee('reviews');
    callReviewsApiOn($tenant)->assertOk()->assertExactJson(['data' => 'reviews']);
})->with([
    'its own tier' => PlanTier::Advanced,
    'a higher tier' => PlanTier::Pro,
]);

test('a lower tier is refused on the API with a stable error the app can match', function () {
    callReviewsApiOn(tenantOnDomain(tier: PlanTier::Basic))
        ->assertForbidden()
        ->assertExactJson(['error' => 'feature_not_in_plan', 'feature' => 'reviews']);
});

test('a lower tier is shown on the web which tier has the feature, not a missing page', function () {
    openReviewsAs(tenantOnDomain(tier: PlanTier::Basic))
        ->assertForbidden()
        ->assertSee('Available on Advanced')
        ->assertSee('Reviews &amp; wishlist', escape: false)
        ->assertSee('Your agency is on Basic, which does not include it.');
});

test('an agency with no live plan is refused every gated feature', function () {
    $tenant = tenantOnDomain();
    Subscription::factory()->for($tenant)->expired()->create(['tier' => PlanTier::Pro]);

    openReviewsAs($tenant)->assertForbidden()->assertSee('Your agency has no live plan.');
    callReviewsApiOn($tenant)->assertForbidden()->assertJsonPath('error', 'feature_not_in_plan');
});

test('the @feature directive and the use-feature ability answer as the route does', function (?PlanTier $tier, bool $allowed) {
    tenancy()->initialize(tenantOnDomain(tier: $tier));
    $staff = User::factory()->role(Role::Staff)->for(tenant())->create();
    $guestGate = Gate::forUser(null);

    expect(trim(Blade::render('@feature(App\Enums\PlanFeature::Reviews) shown @else hidden @endfeature')))->toBe($allowed ? 'shown' : 'hidden')
        ->and($staff->can('use-feature', PlanFeature::Reviews))->toBe($allowed)
        ->and($guestGate->check('use-feature', PlanFeature::Reviews))->toBe($allowed);
})->with([
    'a lower tier' => [PlanTier::Basic, false],
    'the tier' => [PlanTier::Advanced, true],
    'no live plan' => [null, false],
]);

test('outside an agency there is no tier, so the directive and ability say no', function () {
    expect(trim(Blade::render('@feature(App\Enums\PlanFeature::Catalog) shown @else hidden @endfeature')))->toBe('hidden')
        ->and(User::factory()->superAdmin()->make()->can('use-feature', PlanFeature::Catalog))->toBeFalse();
});

test('a gate on a feature that does not exist fails loudly rather than letting the route through', function () {
    Route::middleware(['tenant', 'feature:no_such_feature'])->get('/typo-probe', fn () => 'through');
    $tenant = tenantOnDomain(tier: PlanTier::Pro);

    tenancy()->end();
    actingAsTenantUser($tenant);

    get("http://{$tenant->id}.localhost/typo-probe")
        ->assertServerError();
});
