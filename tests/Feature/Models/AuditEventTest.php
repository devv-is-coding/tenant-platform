<?php

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\Tenant;

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
