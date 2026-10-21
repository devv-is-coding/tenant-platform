<?php

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;

beforeEach(function () {
    // Only the central tables are exercised, so no tenant database is provisioned.
    Event::fake([TenantCreated::class]);
});

test('an agency cannot hold two live subscriptions at once', function () {
    $tenant = Tenant::factory()->create();
    Subscription::factory()->for($tenant)->create();

    expect(fn () => Subscription::factory()->for($tenant)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('an agency keeps any number of ended subscriptions as history', function () {
    $tenant = Tenant::factory()->create();

    Subscription::factory()->for($tenant)->expired()->count(2)->create();
    Subscription::factory()->for($tenant)->create();

    expect($tenant->subscriptions()->count())->toBe(3);
});

test('two agencies each hold their own live subscription', function () {
    Subscription::factory()->for(Tenant::factory())->create();
    Subscription::factory()->for(Tenant::factory())->create();

    expect(Subscription::whereNotNull('active_for_tenant_id')->count())->toBe(2);
});

test('a subscription for an agency that does not exist is refused', function () {
    expect(fn () => Subscription::factory()->create(['tenant_id' => 'no-such-agency']))
        ->toThrow(QueryException::class);

    expect(Subscription::count())->toBe(0);
});

test('an agency with billing history cannot be hard-deleted', function () {
    $tenant = Tenant::factory()->create();
    $subscription = Subscription::factory()->for($tenant)->expired()->create();

    // Deleting through the app is a soft delete and never reaches the foreign
    // key; RESTRICT is what still guards a force delete.
    expect(fn () => $tenant->forceDelete())->toThrow(QueryException::class);

    $this->assertModelExists($tenant);
    $this->assertModelExists($subscription);
});
