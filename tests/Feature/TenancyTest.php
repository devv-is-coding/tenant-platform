<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

afterEach(function () {
    // End tenancy first so the tenant connection is purged; Windows cannot delete
    // an SQLite database file that is still open.
    tenancy()->end();

    // Drops the tenant databases created during the test.
    Tenant::withTrashed()->get()->each->forceDelete();
});

test('creating a tenant provisions and migrates a separate database', function () {
    $tenant = Tenant::create(['id' => 'acme']);

    expect($tenant->database()->getName())->toBe('tenantacme');

    tenancy()->initialize($tenant);

    expect(Schema::connection('tenant')->hasTable('migrations'))->toBeTrue();
});

test('tenant context swaps the default connection but leaves the central one intact', function () {
    $central = config('tenancy.database.central_connection');

    $tenant = Tenant::create(['id' => 'acme']);

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

    $tenant = Tenant::create(['id' => 'acme']);
    tenancy()->initialize($tenant);

    // The model is pinned via the CentralConnection trait, so it must not follow
    // the default connection into the tenant database.
    expect($user->getConnectionName())->toBe(config('tenancy.database.central_connection'))
        ->and(User::whereKey($user->getKey())->exists())->toBeTrue()
        ->and(Schema::connection('tenant')->hasTable('users'))->toBeFalse();
});

test('two tenants get separate databases', function () {
    $acme = Tenant::create(['id' => 'acme']);
    $globex = Tenant::create(['id' => 'globex']);

    expect($acme->database()->getName())->not->toBe($globex->database()->getName());

    tenancy()->initialize($acme);
    $acmeDatabase = DB::connection('tenant')->getDatabaseName();

    tenancy()->initialize($globex);
    $globexDatabase = DB::connection('tenant')->getDatabaseName();

    expect($acmeDatabase)->not->toBe($globexDatabase);
});

test('tenant routes are unreachable from a central domain', function () {
    Tenant::create(['id' => 'acme'])->domains()->create(['domain' => 'acme.localhost']);

    $central = config('tenancy.central_domains')[0];

    get("http://{$central}/")->assertNotFound();
});

test('tenant routes resolve the tenant from its domain', function () {
    Tenant::create(['id' => 'acme'])->domains()->create(['domain' => 'acme.localhost']);

    get('http://acme.localhost/')
        ->assertOk()
        ->assertSee('acme');
});
