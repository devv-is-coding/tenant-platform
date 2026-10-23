<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\PlatformAuditEvent;
use App\Models\Settings;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;

/*
 * The platform decides whether an agency operates, never how, so a super admin
 * must not be able to put an operational action - an approval, a refund - into
 * an agency's own audit log.
 */
test('only tenant lifecycle actions can be written into an agency\'s audit log', function (AuditAction $action) {
    // The guard throws before the tenant database is touched.
    Event::fake([TenantCreated::class]);

    expect(fn () => app(AuditRecorder::class)->recordTenantLifecycleNotice($action, User::factory()->superAdmin()->create(), Tenant::factory()->create()))
        ->toThrow(LogicException::class, "The platform may only write tenant lifecycle notices into a tenant's audit log, not [{$action->value}].");
})->with([
    'item approved' => AuditAction::ItemApproved,
    'refund approved' => AuditAction::RefundApproved,
    'booking cancelled' => AuditAction::BookingCancelled,
]);

test('an unauthenticated agency action records no actor, never 0 (defect 5)', function () {
    $event = withinTenant(Tenant::factory()->create(), fn (): AuditEvent => app(AuditRecorder::class)->recordTenantEvent(AuditAction::AuthLoginFailed, null));

    expect($event->actor_id)->toBeNull()
        ->and($event->actor_role)->toBeNull()
        ->and($event->actor_label)->toBeNull();
});

test('the actor\'s name and role are kept as they were when they acted', function () {
    $agency = Tenant::factory()->create();
    $manager = User::factory()->role(Role::Manager)->for($agency)->create(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Cruz']);

    withinTenant($agency, fn (): AuditEvent => app(AuditRecorder::class)->recordTenantEvent(AuditAction::SettingsUpdated, $manager));
    $manager->update(['first_name' => 'Anabelle', 'role' => Role::Admin]);

    $event = withinTenant($agency, fn (): AuditEvent => AuditEvent::sole());
    expect($event->actor_role)->toBe(Role::Manager)
        ->and($event->actor_label)->toBe('Ana Cruz (Manager)');
});

test('an action is filed beside its subject: an agency record in the agency\'s log', function () {
    $agency = Tenant::factory()->create();
    $admin = User::factory()->role(Role::Admin)->for($agency)->create();

    $event = withinTenant($agency, fn (): AuditEvent|PlatformAuditEvent => app(AuditRecorder::class)->record(AuditAction::SettingsUpdated, $admin, Settings::current()));

    expect($event)->toBeInstanceOf(AuditEvent::class)
        ->and($event->subject_type)->toBe('settings')
        ->and(PlatformAuditEvent::count())->toBe(0);
});

test('an action on a central record inside an agency is filed in the platform log, tagged with the agency', function () {
    $agency = Tenant::factory()->create();
    $admin = User::factory()->role(Role::Admin)->for($agency)->create();

    $event = withinTenant($agency, fn (): AuditEvent|PlatformAuditEvent => app(AuditRecorder::class)->record(AuditAction::UserUpdated, $admin, $admin));

    expect($event)->toBeInstanceOf(PlatformAuditEvent::class)
        ->and($event->tenant_id)->toBe($agency->id)
        ->and($event->subject_type)->toBe('user')
        ->and(withinTenant($agency, fn (): int => AuditEvent::count()))->toBe(0);
});

test('nobody from outside the agency can be recorded as acting inside it', function (Closure $makeActor) {
    $agency = Tenant::factory()->create();
    $actor = $makeActor();

    withinTenant($agency, function () use ($actor, $agency): void {
        expect(fn () => app(AuditRecorder::class)->recordTenantEvent(AuditAction::ItemApproved, $actor))
            ->toThrow(LogicException::class, "does not belong to [{$agency->id}]");
    });
})->with([
    'a super admin' => [fn (): User => User::factory()->superAdmin()->create()],
    'another agency\'s admin' => [fn (): User => User::factory()->role(Role::Admin)->for(Tenant::factory())->create()],
]);

test('an agency action cannot be recorded outside an agency', function () {
    expect(fn () => app(AuditRecorder::class)->recordTenantEvent(AuditAction::ItemApproved, null))
        ->toThrow(LogicException::class, 'An agency action can only be recorded inside an agency');
});

test('a lifecycle action cannot be slipped in as an ordinary agency action', function () {
    withinTenant(Tenant::factory()->create(), function (): void {
        expect(fn () => app(AuditRecorder::class)->recordTenantEvent(AuditAction::TenantSuspended, null))
            ->toThrow(LogicException::class, 'record it with recordTenantLifecycleNotice()');
    });
});

test('a subject without a morph alias is refused rather than stored under its class name', function () {
    $unmapped = new class extends Model {};

    expect(fn () => app(AuditRecorder::class)->recordPlatformEvent(AuditAction::UserUpdated, null, subject: $unmapped))
        ->toThrow(ClassMorphViolationException::class);
});
