<?php

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;

test('only super admins may onboard an agency', function (Role $role, bool $allowed) {
    $user = User::factory()->role($role)->make();

    expect($user->can('create', Tenant::class))->toBe($allowed);
})->with([
    'super admin' => [Role::SuperAdmin, true],
    'admin' => [Role::Admin, false],
    'manager' => [Role::Manager, false],
    'staff' => [Role::Staff, false],
    'customer' => [Role::Customer, false],
]);
