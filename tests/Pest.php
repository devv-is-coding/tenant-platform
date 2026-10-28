<?php

use App\Concerns\RunsInTenant;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\PlanChangeRequest;
use App\Models\RestoreRequest;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;
use Tests\TestCase;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->afterEach(fn () => cleanUpTenancy())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Skip the current test unless the given Fortify feature is enabled.
 */
function skipUnlessFortifyHas(string $feature, ?string $message = null): void
{
    if (! Features::enabled($feature)) {
        TestCase::markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
    }
}

/**
 * End tenancy and drop every tenant the test created, with its database.
 *
 * Runs after every Feature test. Safe when tenancy was never initialized and
 * when no tenant exists.
 */
function cleanUpTenancy(): void
{
    // End tenancy first so the tenant connection is purged; Windows cannot delete
    // an SQLite database file that is still open.
    tenancy()->end();

    // A tenant with users, billing history, a restore or plan request cannot be
    // deleted (RESTRICT), so clear those first. Payments reference invoices.
    SubscriptionPayment::withTrashed()->forceDelete();
    SubscriptionInvoice::withTrashed()->forceDelete();
    Subscription::withTrashed()->forceDelete();
    RestoreRequest::withTrashed()->forceDelete();
    PlanChangeRequest::withTrashed()->forceDelete();
    User::withTrashed()->whereNotNull('tenant_id')->forceDelete();

    // Drops the tenant databases created during the test, and the storage
    // folder tenancy gave each one (uploads, logos), which nothing else removes.
    Tenant::withTrashed()->get()->each(function (Tenant $tenant): void {
        File::deleteDirectory(storage_path("tenant{$tenant->id}"));
        $tenant->forceDelete();
    });
}

/**
 * An agency reachable at http://{id}.localhost, with a factory id so tests
 * stay parallel-safe.
 *
 * A read-only status gets a reason, as the super admin must give one. With a
 * tier, the agency also gets a live subscription on it.
 */
function tenantOnDomain(TenantStatus $status = TenantStatus::Active, ?PlanTier $tier = null): Tenant
{
    $tenant = Tenant::factory()
        ->state(fn (array $attributes): array => ['name' => Str::headline($attributes['id']).' Branch'])
        ->create([
            'status' => $status,
            'status_reason' => $status->isReadOnly() ? 'Subscription unpaid.' : null,
        ]);
    $tenant->domains()->create(['domain' => "{$tenant->id}.localhost"]);

    if ($tier !== null) {
        Subscription::factory()->for($tenant)->create(['tier' => $tier]);
    }

    return $tenant;
}

/**
 * Run the callback inside the tenant's context, then restore whichever
 * context was active before, including none, even when the callback throws.
 * The same switch the app's seeders and commands use (RunsInTenant).
 *
 * Tenant models must be created in here: outside tenancy their factories
 * write to the central database and fail on a missing table.
 *
 * @template TReturn
 *
 * @param  Closure(Tenant): TReturn  $callback
 * @return TReturn
 */
function withinTenant(Tenant $tenant, Closure $callback): mixed
{
    $runner = new class
    {
        use RunsInTenant {
            runInTenant as public;
        }
    };

    return $runner->runInTenant($tenant, $callback);
}

/**
 * Sign in on the web as a new user of the agency with the given role.
 */
function actingAsTenantUser(Tenant $tenant, Role $role = Role::Staff): User
{
    $user = User::factory()->role($role)->for($tenant)->create();

    actingAs($user);

    return $user;
}
