<?php

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\Tenant;

afterEach(function () {
    // End tenancy first so the tenant connection is purged; Windows cannot delete
    // an SQLite database file that is still open.
    tenancy()->end();

    // Drops the tenant databases created during the test.
    Tenant::withTrashed()->get()->each->forceDelete();
});

test('audit events cannot be updated', function () {
    Tenant::factory()->create()->run(function () {
        $event = AuditEvent::create(['action' => AuditAction::TenantSuspended]);

        expect(fn () => $event->update(['action' => AuditAction::TenantReactivated]))
            ->toThrow(LogicException::class, 'Audit events are append-only.');

        expect($event->fresh()?->action)->toBe(AuditAction::TenantSuspended);
    });
});

test('audit events cannot be deleted', function () {
    Tenant::factory()->create()->run(function () {
        $event = AuditEvent::create(['action' => AuditAction::TenantSuspended]);

        expect(fn () => $event->delete())
            ->toThrow(LogicException::class, 'Audit events are append-only.');

        expect(AuditEvent::count())->toBe(1);
    });
});
