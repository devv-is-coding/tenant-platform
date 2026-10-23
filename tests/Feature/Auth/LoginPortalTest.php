<?php

use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Passkeys;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

test('the super admin login screen can be rendered', function () {
    get(route('admin.login'))
        ->assertOk()
        ->assertSee(route('admin.login.store'));
});

test('super admins can log in through the super admin login', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    post(route('admin.login.store'), ['email' => $superAdmin->email, 'password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.dashboard'));

    assertAuthenticatedAs($superAdmin);
});

test('the super admin login refuses other roles', function (Role $role) {
    $user = User::factory()->role($role)->create();

    post(route('admin.login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    assertGuest();
})->with([
    'admin' => Role::Admin,
    'manager' => Role::Manager,
    'staff' => Role::Staff,
    'customer' => Role::Customer,
]);

test('the customer login refuses other roles', function (Role $role) {
    $user = User::factory()->role($role)->create();

    post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    assertGuest();
})->with([
    'super admin' => Role::SuperAdmin,
    'admin' => Role::Admin,
    'manager' => Role::Manager,
    'staff' => Role::Staff,
]);

test('passkey sign-in follows the same portal and active rules as a password', function (string $route, Closure $makeUser, bool $allowed) {
    $request = Request::create(route($route), 'POST');
    $request->setRouteResolver(fn () => Route::getRoutes()->match($request));
    $passkey = (new Passkey)->setRelation('user', $makeUser());

    $allowsLogin = Passkeys::allowsLogin($request, $passkey);

    expect($allowsLogin)->toBe($allowed);
})->with([
    'customer on the customer route' => ['passkey.login', fn () => User::factory()->create(), true],
    'inactive customer on the customer route' => ['passkey.login', fn () => User::factory()->inactive()->create(), false],
    'staff on the customer route' => ['passkey.login', fn () => User::factory()->role(Role::Staff)->create(), false],
    'super admin on the customer route' => ['passkey.login', fn () => User::factory()->superAdmin()->create(), false],
    'super admin on the admin route' => ['admin.passkey.login', fn () => User::factory()->superAdmin()->create(), true],
    'inactive super admin on the admin route' => ['admin.passkey.login', fn () => User::factory()->superAdmin()->inactive()->create(), false],
    'customer on the admin route' => ['admin.passkey.login', fn () => User::factory()->create(), false],
]);

test('the super admin login sends passkeys to the super admin endpoint', function () {
    get(route('admin.login'))
        ->assertSee("submit: '".route('admin.passkey.login')."'", false);
});

test('inactive users cannot log in', function () {
    $user = User::factory()->inactive()->create();

    post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    assertGuest();
});

test('the tenant login screen shows the tenant', function () {
    $tenant = tenantOnDomain();

    get("http://{$tenant->id}.localhost/login")
        ->assertOk()
        ->assertSee($tenant->name);
});

test('tenant users can log in on their own tenant domain', function (Role $role) {
    $tenant = tenantOnDomain();
    $user = User::factory()->role($role)->for($tenant)->create();

    post("http://{$tenant->id}.localhost/login", ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect("http://{$tenant->id}.localhost/dashboard");

    assertAuthenticatedAs($user);
})->with([
    'admin' => Role::Admin,
    'manager' => Role::Manager,
    'staff' => Role::Staff,
]);

test('the tenant login refuses users of another tenant', function () {
    $tenant = tenantOnDomain();
    $otherTenantAdmin = User::factory()->role(Role::Admin)->for(tenantOnDomain())->create();

    post("http://{$tenant->id}.localhost/login", ['email' => $otherTenantAdmin->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    assertGuest();
});

test('the tenant login refuses platform-wide roles', function (Role $role) {
    $tenant = tenantOnDomain();
    $user = User::factory()->role($role)->create();

    post("http://{$tenant->id}.localhost/login", ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    assertGuest();
})->with([
    'super admin' => Role::SuperAdmin,
    'customer' => Role::Customer,
]);

test('nobody from a pending agency can sign in or keep using a session, since it is not live yet', function () {
    $tenant = tenantOnDomain(TenantStatus::Pending);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();

    post("http://{$tenant->id}.localhost/login", ['email' => $admin->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
    assertGuest();

    actingAs($admin)->get("http://{$tenant->id}.localhost/dashboard")->assertForbidden();
});

test('a closed agency\'s staff still sign in, read-only, and are told what is hidden until it resubscribes', function () {
    $tenant = tenantOnDomain(TenantStatus::Closed);
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();

    post("http://{$tenant->id}.localhost/login", ['email' => $staff->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();
    assertAuthenticatedAs($staff);

    get("http://{$tenant->id}.localhost/dashboard")
        ->assertOk()
        ->assertSee('This agency is closed. You can view its records but not change them.')
        ->assertSee('details and money figures are hidden until the agency is on a plan again', escape: false);
});

test('tenant users can open their tenant dashboard', function () {
    $tenant = tenantOnDomain();
    actingAsTenantUser($tenant);

    get("http://{$tenant->id}.localhost/dashboard")
        ->assertOk()
        ->assertSee($tenant->name);
});

/*
 * The settings routes carry the central domain, so route() returns a central
 * URL on a tenant domain rather than failing. Nothing but an assertion catches
 * a link leaking across the boundary.
 */
test('the tenant dashboard navigation links nowhere central', function () {
    $tenant = tenantOnDomain();
    actingAsTenantUser($tenant);

    get("http://{$tenant->id}.localhost/dashboard")
        ->assertOk()
        ->assertDontSee(route('profile.edit'))
        ->assertDontSee(route('security.edit'))
        ->assertDontSee(route('appearance.edit'))
        ->assertDontSee(route('admin.dashboard'));
});

test('the tenant dashboard is forbidden to users of another tenant', function () {
    $tenant = tenantOnDomain();
    actingAsTenantUser(tenantOnDomain(), Role::Admin);

    get("http://{$tenant->id}.localhost/dashboard")
        ->assertForbidden();
});

test('the tenant dashboard redirects guests to the tenant login', function () {
    $tenant = tenantOnDomain();

    get("http://{$tenant->id}.localhost/dashboard")
        ->assertRedirect("http://{$tenant->id}.localhost/login");
});
