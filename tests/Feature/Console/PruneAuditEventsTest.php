<?php

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\PlatformAuditEvent;
use App\Models\Tenant;
use App\Services\Audit\AuditRecorder;

use function Pest\Laravel\artisan;
use function Pest\Laravel\travel;
use function Pest\Laravel\travelBack;
use function Pest\Laravel\travelTo;

/**
 * Record one platform event and one agency event, as of the given number of
 * years ago.
 *
 * @return array{platform: PlatformAuditEvent, agency: AuditEvent}
 */
function auditEventsFromYearsAgo(Tenant $agency, int $years): array
{
    travelTo(now()->subYears($years));

    $recorder = app(AuditRecorder::class);
    $events = [
        'platform' => $recorder->recordPlatformEvent(AuditAction::PlanCreated, null),
        'agency' => withinTenant($agency, fn (): AuditEvent => $recorder->recordTenantEvent(AuditAction::SettingsUpdated, null)),
    ];

    travelBack();

    return $events;
}

/**
 * Get the ids still stored in the agency's log, soft-deleted ones included.
 *
 * @return array{kept: list<int>, soft_deleted: list<int>}
 */
function agencyAuditIds(Tenant $agency): array
{
    return withinTenant($agency, fn (): array => [
        'kept' => AuditEvent::query()->pluck('id')->all(),
        'soft_deleted' => AuditEvent::onlyTrashed()->pluck('id')->all(),
    ]);
}

test('events past three years are soft-deleted and past five deleted for good, in the platform log and every agency\'s', function () {
    $agency = Tenant::factory()->create();
    $recent = auditEventsFromYearsAgo($agency, 2);
    $old = auditEventsFromYearsAgo($agency, 4);
    $expired = auditEventsFromYearsAgo($agency, 6);

    artisan('audit:prune')->assertSuccessful();

    expect(PlatformAuditEvent::query()->pluck('id')->all())->toBe([$recent['platform']->id])
        ->and(PlatformAuditEvent::onlyTrashed()->pluck('id')->all())->toBe([$old['platform']->id])
        ->and(PlatformAuditEvent::withTrashed()->find($expired['platform']->id))->toBeNull()
        ->and(agencyAuditIds($agency))->toBe(['kept' => [$recent['agency']->id], 'soft_deleted' => [$old['agency']->id]])
        ->and(withinTenant($agency, fn (): ?AuditEvent => AuditEvent::withTrashed()->find($expired['agency']->id)))->toBeNull();
});

test('a soft-deleted event is deleted for good once it passes five years', function () {
    $agency = Tenant::factory()->create();
    $old = auditEventsFromYearsAgo($agency, 4);
    artisan('audit:prune')->assertSuccessful();

    travel(1)->years();
    travel(1)->days();
    artisan('audit:prune')->assertSuccessful();

    expect(PlatformAuditEvent::withTrashed()->find($old['platform']->id))->toBeNull()
        ->and(withinTenant($agency, fn (): ?AuditEvent => AuditEvent::withTrashed()->find($old['agency']->id)))->toBeNull();
});

test('a hard limit shorter than the soft one is refused before anything is deleted', function () {
    config(['audit.soft_delete_after_years' => 3, 'audit.hard_delete_after_years' => 2]);
    $agency = Tenant::factory()->create();
    $old = auditEventsFromYearsAgo($agency, 4);

    expect(fn () => artisan('audit:prune')->run())->toThrow(LogicException::class);

    expect(PlatformAuditEvent::query()->find($old['platform']->id))->not->toBeNull();
});
