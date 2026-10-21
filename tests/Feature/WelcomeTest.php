<?php

use App\Enums\Role;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('guests are offered both sign-in portals', function () {
    get(route('home'))
        ->assertOk()
        ->assertSee(config('app.name'))
        ->assertSee(route('login'))
        ->assertSee(route('admin.homepage'))
        ->assertDontSee(route('dashboard'));
});

test('super admins are pointed at their dashboard', function () {
    actingAs(User::factory()->superAdmin()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertSee(route('admin.dashboard'));
});

test('customers are pointed at their dashboard', function () {
    actingAs(User::factory()->role(Role::Customer)->create())
        ->get(route('home'))
        ->assertOk()
        ->assertSee(route('dashboard'))
        ->assertDontSee(route('admin.dashboard'));
});

/*
 * Staff belong on their agency's domain, and route('tenant.dashboard') resolves
 * to the central dashboard from here, so the landing page must send them to the
 * portal rather than link them into the central app.
 */
test('tenant staff are sent to their agency portal, not the central dashboard', function (Role $role) {
    actingAs(User::factory()->role($role)->create())
        ->get(route('home'))
        ->assertOk()
        ->assertSee(route('login'))
        ->assertDontSee(route('admin.dashboard'));
})->with([
    'admin' => Role::Admin,
    'manager' => Role::Manager,
    'staff' => Role::Staff,
]);
