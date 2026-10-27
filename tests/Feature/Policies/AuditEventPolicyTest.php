<?php

use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('only the agency\'s own admin may read its audit log', function (Closure $makeUser, bool $allowed) {
    $tenant = tenantOnDomain();
    $user = $makeUser($tenant);

    expect(withinTenant($tenant, fn (): bool => Gate::forUser($user)->allows('viewAny', AuditEvent::class)))->toBe($allowed);
})->with([
    'its admin' => [fn (Tenant $tenant) => User::factory()->role(Role::Admin)->for($tenant)->create(), true],
    'its manager' => [fn (Tenant $tenant) => User::factory()->role(Role::Manager)->for($tenant)->create(), false],
    'its staff' => [fn (Tenant $tenant) => User::factory()->role(Role::Staff)->for($tenant)->create(), false],
    'another agency\'s admin' => [fn () => User::factory()->role(Role::Admin)->for(tenantOnDomain())->create(), false],
    'a super admin' => [fn () => User::factory()->superAdmin()->create(), false],
]);

test('a lapsed agency\'s admin still reads its log: it is never gated by plan or status', function () {
    $tenant = tenantOnDomain(TenantStatus::Closed, tier: null);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();

    expect(withinTenant($tenant, fn (): bool => Gate::forUser($admin)->allows('viewAny', AuditEvent::class)))->toBeTrue();
});
