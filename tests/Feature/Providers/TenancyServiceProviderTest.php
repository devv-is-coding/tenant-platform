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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Stancl\Tenancy\Events\TenancyInitialized;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/*
 * Livewire::test() never reaches the update endpoint, so these tests drive a
 * real one: open a probe page on the agency's domain, then post the update
 * the browser would send. A probe keeps them apart from any real screen.
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
        "http://{$tenant->id}.localhost".Livewire::getUpdateUri(),
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

/*
 * Under tests Livewire stores uploads on a fake disk of its own, so the folder
 * cannot be observed; what matters is that the upload runs inside the agency,
 * where tenancy roots the real disk in the agency's own storage.
 */
test('a Livewire file upload on an agency\'s domain runs inside that agency, so it lands in the agency\'s storage', function () {
    $tenant = tenantOnDomain();
    tenancy()->end();
    actingAs(User::factory()->role(Role::Admin)->for($tenant)->create());
    Storage::fake(FileUploadConfiguration::disk());

    $uploadedInside = null;
    Event::listen(TenancyInitialized::class, function (TenancyInitialized $event) use (&$uploadedInside): void {
        $uploadedInside = $event->tenancy->tenant?->getTenantKey();
    });

    $uploadUrl = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), absolute: false);

    post("http://{$tenant->id}.localhost{$uploadUrl}", ['files' => [UploadedFile::fake()->image('logo.png')]])
        ->assertOk();

    expect($uploadedInside)->toBe($tenant->id);
});

test('a Livewire preview of a picked file on an agency\'s domain runs inside that agency, and serves the agency\'s file', function () {
    $tenant = tenantOnDomain();
    Storage::fake(FileUploadConfiguration::disk());
    withinTenant($tenant, fn (): bool => FileUploadConfiguration::storage()->put(FileUploadConfiguration::path('logo.png'), 'png bytes'));
    tenancy()->end();

    $previewedInside = null;
    Event::listen(TenancyInitialized::class, function (TenancyInitialized $event) use (&$previewedInside): void {
        $previewedInside = $event->tenancy->tenant?->getTenantKey();
    });

    $previewUrl = URL::temporarySignedRoute('livewire.preview-file', now()->addMinutes(5), ['filename' => 'logo.png'], absolute: false);

    get("http://{$tenant->id}.localhost{$previewUrl}")->assertOk();

    expect($previewedInside)->toBe($tenant->id);
});

/*
 * Under test Livewire always swaps its temporary disk for a local fake
 * (`tmp-for-tests`), so an upload request cannot reach S3 here. This checks
 * what the real upload relies on instead: the disk Livewire is given.
 */
test('on S3, Livewire\'s temporary uploads still go through the app, onto the shared bucket under the agency\'s prefix', function () {
    useAgencyS3OrSkip();
    $tenant = tenantOnDomain();

    $livewireDisk = config()->array('livewire.temporary_file_upload')['disk'];

    withinTenant($tenant, fn (): bool => Storage::disk($livewireDisk)->put('livewire-tmp/logo.png', 'png bytes'));

    expect($livewireDisk)->toBe('uploads')
        ->and(FileUploadConfiguration::isUsingS3())->toBeFalse()
        ->and(Storage::disk('s3')->exists("tenant{$tenant->id}/livewire-tmp/logo.png"))->toBeTrue();

    Storage::disk('s3')->deleteDirectory("tenant{$tenant->id}");
});

test('an active agency on the feature\'s tier can write through Livewire', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Advanced);
    $snapshot = probeSnapshotFor(User::factory()->role(Role::Admin)->for($tenant)->create(), $tenant);

    $response = callProbe($snapshot, $tenant, 'save')->assertOk();

    expect($response->json('components.0.effects.html'))->toContain('saved');
});

test('entering an agency is refused on a cache store that cannot tag, and the app stays central', function () {
    $tenant = Tenant::factory()->create();
    config(['cache.default' => 'database']);

    expect(fn () => tenancy()->initialize($tenant))
        ->toThrow(LogicException::class, 'The [database] cache store cannot tag, so it cannot keep each agency\'s cache apart. Set CACHE_STORE to redis, memcached or array.');

    expect(tenancy()->initialized)->toBeFalse();
});
