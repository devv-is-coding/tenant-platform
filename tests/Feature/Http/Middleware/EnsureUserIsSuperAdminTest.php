<?php

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

afterEach(function () {
    // End tenancy first so the tenant connection is purged; Windows cannot delete
    // an SQLite database file that is still open.
    tenancy()->end();

    // Drops the tenant databases created during the test.
    Tenant::withTrashed()->get()->each->forceDelete();
});

test('super admins can open the admin dashboard', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    actingAs($superAdmin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Dashboard');
});

test('the admin dashboard is forbidden to every other role', function (Role $role) {
    $user = User::factory()->role($role)->create();

    actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertForbidden();
})->with([
    'admin' => Role::Admin,
    'manager' => Role::Manager,
    'staff' => Role::Staff,
    'customer' => Role::Customer,
]);

test('guests opening the admin dashboard are sent to the admin homepage', function () {
    get(route('admin.dashboard'))
        ->assertRedirect(route('admin.homepage'));
});

test('tenant domains have no admin dashboard', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => "{$tenant->id}.localhost"]);
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();

    actingAs($staff)
        ->get("http://{$tenant->id}.localhost/admin")
        ->assertNotFound();
});
