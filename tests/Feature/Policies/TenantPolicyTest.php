<?php

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;

$superAdminsOnly = [
    'super admin' => [Role::SuperAdmin, true],
    'admin' => [Role::Admin, false],
    'manager' => [Role::Manager, false],
    'staff' => [Role::Staff, false],
    'customer' => [Role::Customer, false],
];

test('only super admins may onboard an agency', function (Role $role, bool $allowed) {
    $user = User::factory()->role($role)->make();

    expect($user->can('create', Tenant::class))->toBe($allowed);
})->with($superAdminsOnly);

test('only super admins may browse the agency directory', function (Role $role, bool $allowed) {
    $user = User::factory()->role($role)->make();

    expect($user->can('viewAny', Tenant::class))->toBe($allowed);
})->with($superAdminsOnly);

test('only super admins may view an agency\'s record, even its own staff may not', function (Role $role, bool $allowed) {
    $tenant = Tenant::factory()->make(['id' => 'dumaguete']);
    $user = User::factory()->role($role)->make(['tenant_id' => $role->isTenantRole() ? 'dumaguete' : null]);

    expect($user->can('view', $tenant))->toBe($allowed);
})->with($superAdminsOnly);

test('only super admins may activate, suspend or reactivate an agency, even its own admin may not', function (Role $role, bool $allowed) {
    $tenant = Tenant::factory()->make(['id' => 'dumaguete']);
    $user = User::factory()->role($role)->make(['tenant_id' => $role->isTenantRole() ? 'dumaguete' : null]);

    expect($user->can('changeStatus', $tenant))->toBe($allowed);
})->with($superAdminsOnly);
