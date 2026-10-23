<?php

use App\Models\RestoreRequest;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;

/*
 * The helpers in tests/Pest.php that every tenant test leans on. A broken
 * cleanup leaks tenant databases into later tests; a broken context switch
 * sends tenant writes to the wrong database.
 */

test('cleaning up is safe when tenancy was never initialized and no tenant exists', function () {
    cleanUpTenancy();

    expect(tenancy()->initialized)->toBeFalse()
        ->and(Tenant::withTrashed()->count())->toBe(0);
});

test('cleaning up ends tenancy and drops every tenant and its database, even one with billing history or a restore request', function () {
    $paidTenant = SubscriptionPayment::factory()->create()->tenant;
    $restoringTenant = RestoreRequest::factory()->create()->tenant;
    $deletedTenant = tenantOnDomain();
    $deletedTenant->delete();
    $databases = collect([$paidTenant, $restoringTenant, $deletedTenant])->map(fn (Tenant $tenant): string => database_path($tenant->database()->getName()));
    expect($databases->every(fn (string $database): bool => file_exists($database)))->toBeTrue();
    tenancy()->initialize($paidTenant);

    cleanUpTenancy();

    expect(tenancy()->initialized)->toBeFalse()
        ->and(Tenant::withTrashed()->count())->toBe(0)
        ->and($databases->filter(fn (string $database): bool => file_exists($database)))->toBeEmpty();
});

test('running inside a tenant returns to no tenancy afterwards', function () {
    $tenant = tenantOnDomain();

    $tenantInside = withinTenant($tenant, fn (): ?string => tenant('id'));

    expect($tenantInside)->toBe($tenant->id)
        ->and(tenancy()->initialized)->toBeFalse();
});

test('running inside a tenant returns to the tenant that was active before', function () {
    $previousTenant = tenantOnDomain();
    tenancy()->initialize($previousTenant);

    withinTenant(tenantOnDomain(), fn (): null => null);

    expect(tenant('id'))->toBe($previousTenant->id);
});

test('running inside a tenant restores the context even when the callback throws', function () {
    $tenant = tenantOnDomain();

    expect(fn () => withinTenant($tenant, fn () => throw new RuntimeException('Failed inside the tenant.')))
        ->toThrow(RuntimeException::class, 'Failed inside the tenant.');

    expect(tenancy()->initialized)->toBeFalse();
});
