<?php

use App\Enums\Role;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
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

test('only super admins may put an agency on a plan, even its own admin may not', function (Role $role, bool $allowed) {
    $tenant = Tenant::factory()->make(['id' => 'dumaguete']);
    $user = User::factory()->role($role)->make(['tenant_id' => $role->isTenantRole() ? 'dumaguete' : null]);

    expect($user->can('manageSubscription', $tenant))->toBe($allowed);
})->with($superAdminsOnly);

test('only super admins may browse the platform\'s invoices', function (Role $role, bool $allowed) {
    $user = User::factory()->role($role)->make();

    expect($user->can('viewAny', SubscriptionInvoice::class))->toBe($allowed);
})->with($superAdminsOnly);

test('only super admins may read the platform audit log', function (Role $role, bool $allowed) {
    $user = User::factory()->role($role)->make();

    expect($user->can('viewAny', PlatformAuditEvent::class))->toBe($allowed);
})->with($superAdminsOnly);

test('only super admins may delete an agency or its billing records, even the agency\'s own admin may not', function (string $ability, Closure $makeRecord, Role $role, bool $allowed) {
    $user = User::factory()->role($role)->make(['tenant_id' => $role->isTenantRole() ? 'dumaguete' : null]);

    expect($user->can($ability, $makeRecord()))->toBe($allowed);
})->with([
    'agency' => ['delete', fn () => Tenant::factory()->make(['id' => 'dumaguete'])],
    'subscription' => ['delete', fn () => Subscription::factory()->make(['tenant_id' => 'dumaguete'])],
    'invoice' => ['delete', fn () => SubscriptionInvoice::factory()->make(['subscription_id' => 1, 'tenant_id' => 'dumaguete'])],
    'payment' => ['delete', fn () => SubscriptionPayment::factory()->make(['subscription_invoice_id' => 1, 'tenant_id' => 'dumaguete'])],
])->with($superAdminsOnly);

test('only super admins may delete an agency user, and never another super admin', function (Role $actorRole, Role $targetRole, bool $allowed) {
    $actor = User::factory()->role($actorRole)->make(['tenant_id' => $actorRole->isTenantRole() ? 'dumaguete' : null]);
    $target = User::factory()->role($targetRole)->make(['tenant_id' => $targetRole->isTenantRole() ? 'dumaguete' : null]);

    expect($actor->can('delete', $target))->toBe($allowed);
})->with([
    'super admin deleting staff' => [Role::SuperAdmin, Role::Staff, true],
    'super admin deleting an agency admin' => [Role::SuperAdmin, Role::Admin, true],
    'super admin deleting a super admin' => [Role::SuperAdmin, Role::SuperAdmin, false],
    'agency admin deleting their own staff' => [Role::Admin, Role::Staff, false],
    'manager deleting staff' => [Role::Manager, Role::Staff, false],
]);

test('only super admins may record a payment against an invoice, even the billed agency\'s own admin may not', function (Role $role, bool $allowed) {
    $invoice = SubscriptionInvoice::factory()->make(['subscription_id' => 1, 'tenant_id' => 'dumaguete']);
    $user = User::factory()->role($role)->make(['tenant_id' => $role->isTenantRole() ? 'dumaguete' : null]);

    expect($user->can('recordPayment', $invoice))->toBe($allowed);
})->with($superAdminsOnly);
