<?php

use App\Concerns\RunsInTenant;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Booking;
use App\Models\InventoryBatch;
use App\Models\Item;
use App\Models\Option;
use App\Models\PlanChangeRequest;
use App\Models\RestoreRequest;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\TourProfile;
use App\Models\User;
use App\Services\Booking\BookingDraft;
use App\Services\Booking\BookingDraftLine;
use App\Services\Booking\BookingService;
use App\ValueObjects\DateRange;
use App\ValueObjects\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

// No RefreshDatabase: these tests run against a real MariaDB server, on a
// database of their own that they set up and drop themselves.
pest()->extend(TestCase::class)->in('Concurrency');

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
 * Put the agencies' disks (`public`, `uploads`) on the real S3 bucket, as
 * AGENCY_FILES_DRIVER=s3 does, or skip where no bucket answers: the host and
 * CI have none, the app container reaches the compose SeaweedFS.
 *
 * Tenants get random ids, so each test's files sit under a prefix of their own;
 * remove them with Storage::disk('s3')->deleteDirectory("tenant{id}").
 */
function useAgencyS3OrSkip(): void
{
    $s3 = config()->array('filesystems.disks.s3');
    $endpoint = $s3['endpoint'] ?? null;

    try {
        $reachable = is_string($endpoint) && $endpoint !== '' && Http::timeout(2)->get($endpoint)->successful();
    } catch (ConnectionException) {
        $reachable = false;
    }

    if (! $reachable) {
        TestCase::markTestSkipped('No S3 bucket is reachable here.');
    }

    config([
        'filesystems.disks.public' => $s3,
        'filesystems.disks.uploads' => [...$s3, 'driver' => 's3-via-app'],
        'tenancy.filesystem.root_override.public' => null,
        'tenancy.filesystem.root_override.uploads' => null,
    ]);

    Storage::forgetDisk(['public', 'uploads']);
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

/**
 * A seat on a published tour departing 1 December 2026 and back on the 12th,
 * with the given stock, at 499.00. Call inside the agency.
 */
function seatOnTour(int $stock = 20): Option
{
    $tour = Item::factory()->published()->create(['name' => 'Japan Discovery']);
    TourProfile::factory()->for($tour)->create(['departs_on' => '2026-12-01', 'returns_on' => '2026-12-12']);
    $seat = Option::factory()->for($tour)->create(['name' => 'Solo', 'price' => Money::parse('499.00', 'PHP')]);
    InventoryBatch::factory()->for($seat)->create(['quantity' => $stock]);

    return $seat;
}

/**
 * A room on a published hotel with the given number of rooms, at 199.00 a
 * night. Call inside the agency.
 */
function bookableRoom(int $rooms = 2): Option
{
    $room = Option::factory()->nightly()->for(Item::factory()->hotel()->published()->create(['name' => 'Hotel Palwa']))->create(['name' => 'Room 101', 'price' => Money::parse('199.00', 'PHP')]);
    InventoryBatch::factory()->for($room)->create(['quantity' => $rooms]);

    return $room;
}

/**
 * An e-visa on a published visa item, at 450.00. Call inside the agency.
 */
function bookableVisa(): Option
{
    return Option::factory()->unlimited()->for(Item::factory()->visa()->published()->create(['name' => 'Japan e-Visa']))->create(['price' => Money::parse('450.00', 'PHP')]);
}

/**
 * Book the lines for the customer through BookingService, inside the agency.
 *
 * @param  list<BookingDraftLine>  $lines
 */
function bookFor(User $customer, array $lines): Booking
{
    return app(BookingService::class)->create(new BookingDraft($customer, $lines));
}

/**
 * A stay from one Y-m-d date to another.
 */
function stay(string $startsOn, string $endsOn): DateRange
{
    return DateRange::of($startsOn, $endsOn);
}
