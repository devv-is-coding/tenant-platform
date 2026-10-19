<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

test('super admins opening the customer dashboard are sent to the console', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    actingAs($superAdmin)
        ->get(route('dashboard'))
        ->assertRedirect(route('admin.dashboard'));
});

test('customers stay on the customer dashboard', function () {
    $customer = User::factory()->create();

    actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk();
});
