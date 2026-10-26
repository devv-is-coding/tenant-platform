<?php

use App\Enums\BillingInterval;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    // The directory reads only the central tenants and domains tables, so the
    // per-tenant database provisioning that TenantCreated triggers is skipped.
    Event::fake([TenantCreated::class]);
});

function directory(?User $user = null): Testable
{
    return Livewire::actingAs($user ?? User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.index');
}

/**
 * @return list<string>
 */
function listedTenantIds(Testable $directory): array
{
    return collect($directory->instance()->tenants->items())->pluck('id')->all();
}

test('the directory lists each agency with its status, domain and user count', function () {
    $tenant = Tenant::factory()->create(['id' => 'dumaguete', 'name' => 'Dumaguete City Travel & Tours', 'status' => TenantStatus::Suspended]);
    $tenant->domains()->create(['domain' => 'dumaguete.localhost']);
    User::factory()->role(Role::Admin)->for($tenant)->count(2)->create();

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.tenants.index'))
        ->assertOk()
        ->assertSeeInOrder(['Dumaguete City Travel & Tours', 'dumaguete.localhost', 'Suspended', '2'])
        ->assertSee(route('admin.tenants.show', $tenant));
});

test('the directory\'s user count is the agency\'s staff, never its customers', function () {
    $tenant = Tenant::factory()->create();
    User::factory()->role(Role::Admin)->for($tenant)->create();
    User::factory()->role(Role::Staff)->for($tenant)->create();
    User::factory()->role(Role::Customer)->for($tenant)->count(3)->create();

    $listed = directory()->instance()->tenants->items()[0];

    expect($listed->staff_count)->toBe(2);
});

test('the directory shows each agency\'s tier, plan, price and billing status', function () {
    Subscription::factory()->for(Tenant::factory()->create(['name' => 'Alpha Tours', 'created_at' => now()->subDays(1)]))->create([
        'plan_name' => 'Pro Monthly', 'tier' => PlanTier::Pro, 'amount_minor' => 350000, 'interval' => BillingInterval::Monthly,
    ]);
    Subscription::factory()->for(Tenant::factory()->create(['name' => 'Beta Tours', 'created_at' => now()->subDays(2)]))->pastDue()->create([
        'plan_name' => 'Basic Annual', 'tier' => PlanTier::Basic, 'amount_minor' => 1800000, 'interval' => BillingInterval::Annual,
    ]);
    Subscription::factory()->for(Tenant::factory()->create(['name' => 'Gamma Tours', 'created_at' => now()->subDays(3)]))->expired()->create([
        'plan_name' => 'Old Plan', 'tier' => PlanTier::Pro,
    ]);

    directory()
        ->assertSeeInOrder([
            'Alpha Tours', 'Pro', 'Pro Monthly', '₱3,500.00', 'Monthly', 'Active',
            'Beta Tours', 'Basic', 'Basic Annual', '₱18,000.00', 'Annual', 'Past due',
            'Gamma Tours', 'No plan',
        ])
        ->assertDontSee('Old Plan');
});

test('the directory pages through agencies newest first', function () {
    Tenant::factory()
        ->count(16)
        ->sequence(fn (Sequence $sequence) => ['id' => "agency-{$sequence->index}", 'created_at' => now()->subDays($sequence->index)])
        ->create();

    $directory = directory();

    expect(listedTenantIds($directory))->toHaveCount(15)
        ->and(listedTenantIds($directory)[0])->toBe('agency-0');

    expect(listedTenantIds($directory->call('nextPage')))->toBe(['agency-15']);
});

test('the status filter lists only agencies in that status', function () {
    Tenant::factory()->create(['id' => 'active-agency']);
    Tenant::factory()->create(['id' => 'suspended-agency', 'status' => TenantStatus::Suspended]);

    expect(listedTenantIds(directory()->set('status', (string) TenantStatus::Suspended->value)))
        ->toBe(['suspended-agency']);
});

test('searching matches the agency name regardless of case, its id or its domain', function (string $term) {
    $dumaguete = Tenant::factory()->create(['id' => 'dumaguete', 'name' => 'Dumaguete City Travel & Tours']);
    $dumaguete->domains()->create(['domain' => 'dgte.localhost']);
    Tenant::factory()->create(['id' => 'tanjay', 'name' => 'Tanjay City Travel & Tours'])
        ->domains()->create(['domain' => 'tanjay.localhost']);

    expect(listedTenantIds(directory()->set('search', $term)))->toBe(['dumaguete']);
})->with([
    'name, lowercase' => 'dumaguete city',
    'name, uppercase' => 'DUMAGUETE',
    'id' => 'dumag',
    'domain' => 'dgte',
]);

test('changing the search or filter returns to the first page', function (string $property, string $value) {
    Tenant::factory()->count(16)->create();

    directory()
        ->call('nextPage')
        ->assertSet('paginators.page', 2)
        ->set($property, $value)
        ->assertSet('paginators.page', 1);
})->with([
    'search' => ['search', 'a'],
    'status' => ['status', '2'],
]);

test('the directory is forbidden to tenant admins', function () {
    actingAs(User::factory()->role(Role::Admin)->create())
        ->get(route('admin.tenants.index'))
        ->assertForbidden();
});

test('the directory component refuses non-super-admins without the console middleware', function () {
    // Livewire update requests skip the route middleware, so the component's
    // own authorization is what keeps the directory private.
    directory(User::factory()->role(Role::Admin)->create())->assertForbidden();
});
