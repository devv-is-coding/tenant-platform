<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\freezeSecond;

afterEach(function () {
    // End tenancy first so the tenant connection is purged; Windows cannot delete
    // an SQLite database file that is still open.
    tenancy()->end();

    // Drops the tenant databases created during the test.
    Tenant::withTrashed()->get()->each->forceDelete();
});

test('a new tenant starts pending and stores its status in a real column', function () {
    $tenant = Tenant::create(['id' => 'acme']);

    $row = DB::table('tenants')->where('id', 'acme')->first();
    expect($tenant->status)->toBe(TenantStatus::Pending)
        ->and($tenant->fresh()?->status)->toBe(TenantStatus::Pending);
    expect($row->status)->toEqual(1)
        ->and(json_decode($row->data, true))->not->toHaveKey('status');
});

test('a status change is stored in its own columns and reads back typed', function () {
    freezeSecond();
    $tenant = Tenant::factory()->create();

    $tenant->update([
        'status' => TenantStatus::Suspended,
        'status_reason' => 'Subscription unpaid 14 days past due.',
        'status_changed_on' => now(),
        'status_changed_by' => 1,
    ]);

    $row = DB::table('tenants')->where('id', $tenant->id)->first();
    expect($row->status)->toEqual(4)
        ->and($row->status_reason)->toBe('Subscription unpaid 14 days past due.')
        ->and($row->status_changed_by)->toEqual(1)
        ->and(json_decode($row->data, true))->not->toHaveKeys(['status', 'status_reason', 'status_changed_on', 'status_changed_by']);
    $tenant = $tenant->fresh();
    expect($tenant?->status)->toBe(TenantStatus::Suspended)
        ->and($tenant?->status_changed_on)->toEqual(now())
        ->and($tenant?->status_changed_by)->toBe(1);
});

test('tenants can be filtered by status in a query', function () {
    $active = Tenant::factory()->create();
    Tenant::create(['id' => 'pending-agency']);

    $activeIds = Tenant::where('status', TenantStatus::Active)->pluck('id');
    $activeIdsBySql = DB::table('tenants')->where('status', 2)->pluck('id');

    expect($activeIds->all())->toBe([$active->id]);
    expect($activeIdsBySql->all())->toBe([$active->id]);
});

test('a new tenant database is provisioned with one default settings row', function () {
    $tenant = Tenant::factory()->create();

    $settings = $tenant->run(fn () => DB::table('settings')->get());

    expect($settings)->toHaveCount(1)
        ->and($settings->first()->booking_hold_minutes)->toEqual(1440)
        ->and($settings->first()->requires_listing_approval)->toEqual(1);
});
