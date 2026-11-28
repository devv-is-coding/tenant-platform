<?php

use App\Enums\AuditAction;
use App\Enums\TenantStatus;
use App\Jobs\ProvisionAgencyDatabase;
use App\Models\PlatformAuditEvent;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Exceptions\TenantDatabaseAlreadyExistsException;

/**
 * Create the agency's database by hand, as an earlier attempt (or something
 * else) would have left it.
 */
function leaveDatabaseBehind(Tenant $tenant): void
{
    $tenant->database()->manager()->createDatabase($tenant);
}

test('setting up an agency creates, migrates and seeds its database, then leaves it waiting for activation', function () {
    $tenant = agencyBeingSetUp();

    ProvisionAgencyDatabase::dispatchSync($tenant);

    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Pending)
        ->and($tenant->status_reason)->toBeNull()
        ->and($tenant->run(fn () => DB::table('settings')->count()))->toBe(1);
    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::TenantProvisioned)
        ->and($event->tenant_id)->toBe($tenant->id)
        ->and($event->actor_id)->toBeNull();
});

test('a failed setup keeps the agency being set up, with the error on it and in the platform audit log', function () {
    $tenant = agencyBeingSetUp();
    leaveDatabaseBehind($tenant);
    $error = "Tenant cannot be created. Reason: Database {$tenant->database()->getName()} already exists.";

    expect(fn () => ProvisionAgencyDatabase::dispatchSync($tenant))->toThrow(TenantDatabaseAlreadyExistsException::class);

    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Provisioning)
        ->and($tenant->status_reason)->toBe("Setup failed: {$error}");
    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::TenantProvisioningFailed)
        ->and($event->description)->toBe($error);
});

test('a resumed setup migrates and seeds the database an earlier attempt created', function () {
    $tenant = agencyBeingSetUp('Setup failed: Connection refused.');
    leaveDatabaseBehind($tenant);

    ProvisionAgencyDatabase::dispatchSync($tenant, resuming: true);

    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Pending)
        ->and($tenant->status_reason)->toBeNull()
        ->and($tenant->run(fn () => DB::table('settings')->count()))->toBe(1);
});

test('an agency created in any other status is set up at once and keeps its status', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);

    expect($tenant->fresh()?->status)->toBe(TenantStatus::Active)
        ->and($tenant->run(fn () => DB::table('settings')->count()))->toBe(1)
        ->and(PlatformAuditEvent::count())->toBe(0);
});
