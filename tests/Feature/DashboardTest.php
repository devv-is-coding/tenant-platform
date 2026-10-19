<?php

use App\Enums\Role;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('guests are redirected to the login page', function () {
    $response = get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    actingAs($user);

    $response = get(route('dashboard'));
    $response->assertOk();
});

test('the console navigation is shown to super admins only', function () {
    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee(route('admin.tenants.create'));

    actingAs(User::factory()->role(Role::Customer)->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('admin.tenants.create'))
        ->assertDontSee(route('admin.dashboard'));
});
