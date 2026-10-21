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

test('recorded changes read as one line each, values as stored', function (array $changes, array $lines) {
    $event = PlatformAuditEvent::create(['action' => AuditAction::TenantSuspended, 'changes' => $changes]);

    expect($event->fresh()?->changeSummary())->toBe($lines);
})->with([
    'an old and new value' => [['status' => ['old' => 2, 'new' => 4]], ['status: 2 → 4']],
    'several fields' => [['plan_name' => ['old' => 'Agency Standard', 'new' => 'Agency Plus'], 'amount_minor' => ['old' => 250000, 'new' => 900000]], ['plan_name: Agency Standard → Agency Plus', 'amount_minor: 250000 → 900000']],
    'a value set for the first time' => [['ends_on' => ['old' => null, 'new' => '2026-12-01']], ['ends_on: — → 2026-12-01']],
    'a flag' => [['is_active' => ['old' => true, 'new' => false]], ['is_active: true → false']],
    'a plain value' => [['reason' => 'Issued in error.'], ['reason: Issued in error.']],
    'a nested value' => [['domains' => ['dumaguete.test', 'dgte.test']], ['domains: ["dumaguete.test","dgte.test"]']],
]);

test('an event with no recorded changes summarises to nothing', function () {
    expect(PlatformAuditEvent::create(['action' => AuditAction::AuthLogin])->changeSummary())->toBe([]);
});
