<?php

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withCookie;

uses(RefreshDatabase::class);

test('creating a tenant provisions and migrates a separate database', function () {
    $tenant = Tenant::factory()->create();

    expect($tenant->database()->getName())->toBe("tenant{$tenant->id}");

    tenancy()->initialize($tenant);

    expect(Schema::connection('tenant')->hasTable('migrations'))->toBeTrue();
});

test('tenant context swaps the default connection but leaves the central one intact', function () {
    $central = config('tenancy.database.central_connection');

    $tenant = Tenant::factory()->create();

    expect(config('database.default'))->toBe($central);

    tenancy()->initialize($tenant);
    expect(config('database.default'))->toBe('tenant')
        ->and(DB::connection('tenant')->getDatabaseName())->not->toBe(
            DB::connection($central)->getDatabaseName()
        );

    tenancy()->end();
    expect(config('database.default'))->toBe($central);
});

test('users stay in the central database while a tenant is active', function () {
    $user = User::create([
        'username' => 'central1',
        'email' => 'central1@example.test',
        'password' => 'password',
        'first_name' => 'Cen',
        'last_name' => 'Tral',
    ]);

    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);

    // The model is pinned via the CentralConnection trait, so it must not follow
    // the default connection into the tenant database.
    expect($user->getConnectionName())->toBe(config('tenancy.database.central_connection'))
        ->and(User::whereKey($user->getKey())->exists())->toBeTrue()
        ->and(Schema::connection('tenant')->hasTable('users'))->toBeFalse();
});

test('two tenants get separate databases', function () {
    $firstTenant = Tenant::factory()->create();
    $secondTenant = Tenant::factory()->create();

    expect($firstTenant->database()->getName())->not->toBe($secondTenant->database()->getName());

    tenancy()->initialize($firstTenant);
    $firstDatabase = DB::connection('tenant')->getDatabaseName();

    tenancy()->initialize($secondTenant);
    $secondDatabase = DB::connection('tenant')->getDatabaseName();

    expect($firstDatabase)->not->toBe($secondDatabase);
});

/**
 * Point the cache at the real Redis store, under a prefix of the test's own,
 * or skip where none is reachable (the host and CI have no Redis; the app
 * container does). The array store cannot show isolation across contexts:
 * tenancy builds a new cache manager, and so a new, empty array, each time.
 */
function useRedisCacheOrSkip(): void
{
    if (! extension_loaded('redis')) {
        test()->markTestSkipped('The redis extension is not loaded here.');
    }

    try {
        Redis::connection('cache')->ping();
    } catch (Throwable) {
        test()->markTestSkipped('No Redis server is reachable here.');
    }

    config(['cache.default' => 'redis', 'cache.prefix' => 'test-'.Str::random(8).'-']);
}

test('an agency\'s cache keys are tagged with the agency, never written as the plain key', function () {
    $dumaguete = Tenant::factory()->create();

    $seen = withinTenant($dumaguete, function (): array {
        Cache::put('greeting', 'dumaguete');

        return ['agency' => Cache::get('greeting'), 'untagged' => Cache::store()->get('greeting')];
    });

    expect($seen)->toBe(['agency' => 'dumaguete', 'untagged' => null]);
});

test('on Redis, each agency\'s cache is its own across requests, and flushing it leaves the others\' and the central cache', function () {
    useRedisCacheOrSkip();
    $dumaguete = Tenant::factory()->create();
    $tanjay = Tenant::factory()->create();
    Cache::put('greeting', 'central', 60);
    withinTenant($dumaguete, fn () => Cache::put('greeting', 'dumaguete', 60));
    withinTenant($tanjay, fn () => Cache::put('greeting', 'tanjay', 60));

    withinTenant($dumaguete, fn () => Cache::flush());

    expect(withinTenant($dumaguete, fn () => Cache::get('greeting')))->toBeNull()
        ->and(withinTenant($tanjay, fn () => Cache::get('greeting')))->toBe('tanjay')
        ->and(Cache::get('greeting'))->toBe('central');

    withinTenant($tanjay, fn () => Cache::flush());
    Cache::forget('greeting');
});

test('a sign-in on an agency\'s domain keeps its session in the agency\'s own database, and the console\'s stays central', function () {
    // The suite keeps sessions in memory; the bug was in where the database driver writes them.
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $central = DB::connection(config()->string('tenancy.database.central_connection'));
    get(route('admin.login'))->assertOk();

    // Fortify's login builds the session before tenancy runs; it must still land in the agency.
    $signIn = post("http://{$tenant->id}.localhost/login", ['email' => $admin->email, 'password' => 'password']);

    $signIn->assertRedirect("http://{$tenant->id}.localhost/dashboard");
    tenancy()->end();
    expect(withinTenant($tenant, fn (): array => DB::table('sessions')->pluck('user_id')->all()))->toBe([$admin->id])
        ->and($central->table('sessions')->whereNotNull('user_id')->count())->toBe(0)
        ->and($central->table('sessions')->count())->toBe(1);

    // The next agency page finds that session, so the admin is still signed in.
    tenancy()->end();
    $sessionCookie = config()->string('session.cookie');
    withCookie($sessionCookie, (string) $signIn->getCookie($sessionCookie)?->getValue())
        ->get("http://{$tenant->id}.localhost/dashboard")
        ->assertOk();
});

test('an agency\'s domain has no cookie sign-in route for single-page apps, which would start a session outside the agency', function () {
    $tenant = tenantOnDomain();

    get("http://{$tenant->id}.localhost/sanctum/csrf-cookie")->assertNotFound();
});

test('tenant routes are unreachable from a central domain', function () {
    tenantOnDomain();

    $central = config('tenancy.central_domains')[0];

    get("http://{$central}/")->assertNotFound();
});

test('tenant routes resolve the tenant from its domain', function () {
    $tenant = tenantOnDomain();

    get("http://{$tenant->id}.localhost/")
        ->assertOk()
        ->assertSee($tenant->id);
});
