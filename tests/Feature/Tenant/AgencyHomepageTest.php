<?php

use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

test('a guest sees the agency\'s homepage with a way to sign in', function () {
    $tenant = tenantOnDomain();

    get("http://{$tenant->id}.localhost")
        ->assertOk()
        ->assertSee('Welcome to '.$tenant->name)
        ->assertSee("http://{$tenant->id}.localhost/login");
});

test('the homepage is public, so it shows nothing about the agency beyond its name', function () {
    $tenant = tenantOnDomain(TenantStatus::Suspended);
    Subscription::factory()->for($tenant)->create(['plan_name' => 'Agency Pro Monthly']);
    User::factory()->role(Role::Admin)->for($tenant)->create(['first_name' => 'Ignacio', 'last_name' => 'Ramos']);

    get("http://{$tenant->id}.localhost")
        ->assertOk()
        ->assertDontSee('Subscription unpaid.')
        ->assertDontSee('Suspended')
        ->assertDontSee('Agency Pro Monthly')
        ->assertDontSee('Ignacio');
});

test('the agency\'s signed-in staff skip the homepage for the dashboard', function (Role $role) {
    $tenant = tenantOnDomain();

    actingAs(User::factory()->role($role)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost")
        ->assertRedirect("http://{$tenant->id}.localhost/dashboard");
})->with([
    'admin' => Role::Admin,
    'manager' => Role::Manager,
    'staff' => Role::Staff,
]);

test('anyone else signed in sees the homepage like a guest', function (Closure $makeUser) {
    $tenant = tenantOnDomain();

    actingAs($makeUser($tenant))
        ->get("http://{$tenant->id}.localhost")
        ->assertOk()
        ->assertSee("http://{$tenant->id}.localhost/login");
})->with([
    'the agency\'s own customer' => [fn (Tenant $tenant) => User::factory()->role(Role::Customer)->for($tenant)->create()],
    'another agency\'s admin' => [fn () => User::factory()->role(Role::Admin)->for(tenantOnDomain())->create()],
]);

test('signing in on the agency\'s domain lands on its dashboard', function () {
    $tenant = tenantOnDomain();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();

    post("http://{$tenant->id}.localhost/login", ['email' => $staff->email, 'password' => 'password'])
        ->assertRedirect("http://{$tenant->id}.localhost/dashboard");
});

test('a guest on a deep link lands on the homepage and is returned to the link after signing in', function () {
    // The dashboard is the only agency page yet, and also where a sign-in
    // lands anyway, so a probe stands in for a deeper one.
    Route::middleware(['tenant', 'auth', EnsureUserBelongsToTenant::class])->get('/bookings-probe', fn () => 'bookings');
    $tenant = tenantOnDomain();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();

    get("http://{$tenant->id}.localhost/bookings-probe")->assertRedirect("http://{$tenant->id}.localhost");
    get("http://{$tenant->id}.localhost")->assertOk();

    post("http://{$tenant->id}.localhost/login", ['email' => $staff->email, 'password' => 'password'])
        ->assertRedirect("http://{$tenant->id}.localhost/bookings-probe");
});
