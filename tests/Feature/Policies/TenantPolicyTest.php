<?php

use App\Enums\Role;
use App\Models\PlanFeatureSetting;
use App\Models\PlatformAuditEvent;
use App\Models\RestoreRequest;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
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

test('only super admins may restore a deleted agency or its records, even the agency\'s own admin may not', function (Closure $makeRecord, Role $role, bool $allowed) {
    $user = User::factory()->role($role)->make(['tenant_id' => $role->isTenantRole() ? 'dumaguete' : null]);

    expect($user->can('restore', $makeRecord()))->toBe($allowed);
})->with([
    'agency' => [fn () => Tenant::factory()->make(['id' => 'dumaguete', 'deleted_at' => now()])],
    'user' => [fn () => User::factory()->role(Role::Staff)->make(['tenant_id' => 'dumaguete'])],
    'subscription' => [fn () => Subscription::factory()->make(['tenant_id' => 'dumaguete'])],
    'invoice' => [fn () => SubscriptionInvoice::factory()->make(['subscription_id' => 1, 'tenant_id' => 'dumaguete'])],
    'payment' => [fn () => SubscriptionPayment::factory()->make(['subscription_invoice_id' => 1, 'tenant_id' => 'dumaguete'])],
])->with($superAdminsOnly);

test('only super admins may assign or revoke an agency\'s admins, even its own admin may not', function (Role $role, bool $allowed) {
    $tenant = Tenant::factory()->make(['id' => 'dumaguete']);
    $user = User::factory()->role($role)->make(['tenant_id' => $role->isTenantRole() ? 'dumaguete' : null]);

    expect($user->can('manageAdmins', $tenant))->toBe($allowed);
})->with($superAdminsOnly);

test('a deleted agency can be restored but no longer changed, and a live one cannot be restored', function () {
    $superAdmin = User::factory()->superAdmin()->make();
    $deleted = Tenant::factory()->make(['deleted_at' => now()]);
    $live = Tenant::factory()->make();

    expect($superAdmin->can('restore', $deleted))->toBeTrue()
        ->and($superAdmin->can('restore', $live))->toBeFalse()
        ->and($superAdmin->can('changeStatus', $deleted))->toBeFalse()
        ->and($superAdmin->can('manageSubscription', $deleted))->toBeFalse()
        ->and($superAdmin->can('manageAdmins', $deleted))->toBeFalse()
        ->and($superAdmin->can('delete', $deleted))->toBeFalse();
});

test('a super admin account is never restored from an agency page', function () {
    expect(User::factory()->superAdmin()->make()->can('restore', User::factory()->superAdmin()->make()))->toBeFalse();
});

test('only the agency\'s own admin may ask for a restore', function (Role $role, ?string $tenantId, bool $allowed) {
    $user = User::factory()->role($role)->make(['tenant_id' => $tenantId]);

    expect($user->can('create', [RestoreRequest::class, Tenant::factory()->make(['id' => 'dumaguete'])]))->toBe($allowed);
})->with([
    'the agency\'s admin' => [Role::Admin, 'dumaguete', true],
    'another agency\'s admin' => [Role::Admin, 'tanjay', false],
    'the agency\'s manager' => [Role::Manager, 'dumaguete', false],
    'the agency\'s staff' => [Role::Staff, 'dumaguete', false],
    'a super admin' => [Role::SuperAdmin, null, false],
    'a customer' => [Role::Customer, null, false],
]);

test('only super admins may see and decide restore requests', function (Role $role, bool $allowed) {
    $user = User::factory()->role($role)->make(['tenant_id' => $role->isTenantRole() ? 'dumaguete' : null]);

    expect($user->can('viewAny', RestoreRequest::class))->toBe($allowed)
        ->and($user->can('decide', RestoreRequest::factory()->make(['tenant_id' => 'dumaguete', 'restorable_id' => 1, 'requested_by' => 1])))->toBe($allowed);
})->with($superAdminsOnly);

test('only super admins may see or change the price list', function (string $ability, Closure $subject, Role $role, bool $allowed) {
    $user = User::factory()->role($role)->make(['tenant_id' => $role->isTenantRole() ? 'dumaguete' : null]);

    expect($user->can($ability, $subject()))->toBe($allowed);
})->with([
    'browse' => ['viewAny', fn () => SubscriptionPlan::class],
    'add' => ['create', fn () => SubscriptionPlan::class],
    'reprice' => ['update', fn () => SubscriptionPlan::factory()->make()],
    'withdraw' => ['delete', fn () => SubscriptionPlan::factory()->make()],
])->with($superAdminsOnly);

test('only super admins may see or move which tier a feature starts at, even an agency\'s own admin may not', function (string $ability, Role $role, bool $allowed) {
    $user = User::factory()->role($role)->make(['tenant_id' => $role->isTenantRole() ? 'dumaguete' : null]);

    expect($user->can($ability, PlanFeatureSetting::class))->toBe($allowed);
})->with([
    'see the matrix' => ['viewAny'],
    'move a feature' => ['update'],
])->with($superAdminsOnly);

test('only super admins may record a payment against an invoice, even the billed agency\'s own admin may not', function (Role $role, bool $allowed) {
    $invoice = SubscriptionInvoice::factory()->make(['subscription_id' => 1, 'tenant_id' => 'dumaguete']);
    $user = User::factory()->role($role)->make(['tenant_id' => $role->isTenantRole() ? 'dumaguete' : null]);

    expect($user->can('recordPayment', $invoice))->toBe($allowed);
})->with($superAdminsOnly);

test('the agency\'s own staff, and only they, may open its dashboard', function (Role $role, ?string $tenantId, bool $allowed) {
    $tenant = Tenant::factory()->make(['id' => 'dumaguete']);
    $user = User::factory()->role($role)->make(['tenant_id' => $tenantId]);

    expect($user->can('viewAgencyDashboard', $tenant))->toBe($allowed);
})->with([
    'its admin' => [Role::Admin, 'dumaguete', true],
    'its manager' => [Role::Manager, 'dumaguete', true],
    'its staff' => [Role::Staff, 'dumaguete', true],
    'its customer' => [Role::Customer, 'dumaguete', false],
    'another agency\'s admin' => [Role::Admin, 'tanjay', false],
    'a super admin' => [Role::SuperAdmin, null, false],
]);

test('only the agency\'s own admin may see its plan and its team', function (string $ability, Role $role, ?string $tenantId, bool $allowed) {
    $tenant = Tenant::factory()->make(['id' => 'dumaguete']);
    $user = User::factory()->role($role)->make(['tenant_id' => $tenantId]);

    expect($user->can($ability, $tenant))->toBe($allowed);
})->with(['viewAgencyPlan', 'viewAgencyTeam'])->with([
    'its admin' => [Role::Admin, 'dumaguete', true],
    'its manager' => [Role::Manager, 'dumaguete', false],
    'its staff' => [Role::Staff, 'dumaguete', false],
    'its customer' => [Role::Customer, 'dumaguete', false],
    'another agency\'s admin' => [Role::Admin, 'tanjay', false],
    'a super admin' => [Role::SuperAdmin, null, false],
]);
