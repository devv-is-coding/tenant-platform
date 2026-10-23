<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\get;

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
