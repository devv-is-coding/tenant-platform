<?php

use App\Enums\PlanFeature;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Models\Settings;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\AgencyPolicy;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Component;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/*
 * Livewire::test() never reaches the update endpoint, so these tests drive a
 * real one: open a probe page on the agency's domain, then post the update
 * the browser would send. No agency-side Livewire page exists yet.
 */

beforeEach(function () {
    Livewire::component('agency-probe', new class extends Component
    {
        public string $agency = '';

        public function whichAgency(): void
        {
            $this->agency = (string) tenant('id');
        }

        public function save(): void
        {
            $this->authorize('update', Settings::sole());
            $this->agency = 'saved';
        }

        public function render(): string
        {
            return '<div>{{ $agency }}</div>';
        }
    });

    Route::middleware(['tenant', 'auth', EnsureUserBelongsToTenant::class])
        ->get('/agency-probe', fn () => Blade::render('<livewire:agency-probe />'));

    // The kind of policy agency screens will have: only writes are guarded.
    Gate::policy(Settings::class, (new class extends AgencyPolicy
    {
        protected function feature(): ?PlanFeature
        {
            return PlanFeature::Reviews;
        }

        public function update(User $user, Settings $settings): bool
        {
            return true;
        }
    })::class);
});

/**
 * Open the probe page as the user and return its component snapshot.
 *
 * Tenancy is ended first, as it would be at the start of a real request: the
 * test application keeps the previous request's tenant initialized.
 */
function probeSnapshotFor(User $user, Tenant $tenant): string
{
    tenancy()->end();

    $page = actingAs($user)->get("http://{$tenant->id}.localhost/agency-probe")->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);

    return html_entity_decode($matches[1]);
}

/**
 * Call a probe method through the Livewire endpoint on the agency's domain,
 * the way the browser does.
 */
function callProbe(string $snapshot, Tenant $tenant, string $method): TestResponse
{
    tenancy()->end();

    return postJson(
        "http://{$tenant->id}.localhost".app('livewire')->getUpdateUri(),
        ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => $method, 'params' => [], 'metadata' => []]]]]],
        ['X-Livewire' => 'true'],
    );
}

test('a Livewire update on an agency\'s domain runs inside that agency', function () {
    $tenant = tenantOnDomain();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();

    $response = callProbe(probeSnapshotFor($staff, $tenant), $tenant, 'whichAgency')->assertOk();

    expect($response->json('components.0.effects.html'))->toContain($tenant->id);
});

test('a Livewire update re-checks the page\'s staff-only guard', function () {
    $tenant = tenantOnDomain();
    $snapshot = probeSnapshotFor(User::factory()->role(Role::Staff)->for($tenant)->create(), $tenant);
    actingAs(User::factory()->role(Role::Customer)->for($tenant)->create());

    callProbe($snapshot, $tenant, 'whichAgency')->assertForbidden();
});

test('a restricted agency\'s staff can still use its screens, but every Livewire write is refused', function (TenantStatus $status) {
    $tenant = tenantOnDomain($status, PlanTier::Pro);
    $snapshot = probeSnapshotFor(User::factory()->role(Role::Admin)->for($tenant)->create(), $tenant);

    callProbe($snapshot, $tenant, 'whichAgency')->assertOk();
    callProbe($snapshot, $tenant, 'save')
        ->assertForbidden()
        ->assertJson(['message' => 'This agency is '.mb_strtolower($status->label()).', so its records are read-only.']);
})->with([
    'paused' => TenantStatus::Paused,
    'suspended' => TenantStatus::Suspended,
    'closed' => TenantStatus::Closed,
]);

test('a downgraded agency still opens a lost feature\'s screen, but every Livewire write is refused', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $snapshot = probeSnapshotFor(User::factory()->role(Role::Admin)->for($tenant)->create(), $tenant);

    callProbe($snapshot, $tenant, 'whichAgency')->assertOk();
    callProbe($snapshot, $tenant, 'save')
        ->assertForbidden()
        ->assertJson(['message' => 'Reviews & wishlist is available on Advanced.']);
});

test('an active agency on the feature\'s tier can write through Livewire', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Advanced);
    $snapshot = probeSnapshotFor(User::factory()->role(Role::Admin)->for($tenant)->create(), $tenant);

    $response = callProbe($snapshot, $tenant, 'save')->assertOk();

    expect($response->json('components.0.effects.html'))->toContain('saved');
});
