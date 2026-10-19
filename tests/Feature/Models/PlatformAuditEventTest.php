<?php

use App\Enums\AuditAction;
use App\Models\PlatformAuditEvent;

test('platform audit events cannot be updated', function () {
    $event = PlatformAuditEvent::create(['action' => AuditAction::TenantCreated]);

    expect(fn () => $event->update(['action' => AuditAction::TenantDeleted]))
        ->toThrow(LogicException::class, 'Platform audit events are append-only.');

    expect($event->fresh()?->action)->toBe(AuditAction::TenantCreated);
});

test('platform audit events cannot be deleted', function () {
    $event = PlatformAuditEvent::create(['action' => AuditAction::TenantCreated]);

    expect(fn () => $event->delete())
        ->toThrow(LogicException::class, 'Platform audit events are append-only.');

    expect(PlatformAuditEvent::count())->toBe(1);
});
