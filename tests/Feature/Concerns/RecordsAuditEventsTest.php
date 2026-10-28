<?php

use App\Concerns\RecordsAuditEvents;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\PlatformAuditEvent;
use App\Models\Settings;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * An agency feature that records through the trait.
 */
function auditingFeature(): object
{
    return new class
    {
        use RecordsAuditEvents {
            recordAuditEvent as public;
        }
    };
}

test('the signed-in user is the actor, and an agency record lands in the agency\'s own log', function () {
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    actingAs($admin);

    $event = withinTenant($tenant, fn () => auditingFeature()->recordAuditEvent(
        AuditAction::SettingsUpdated,
        Settings::current(),
        changes: ['deposit_percentage' => ['old' => 50, 'new' => 40]],
    ));

    expect($event)->toBeInstanceOf(AuditEvent::class)
        ->and($event->actor_id)->toBe($admin->id)
        ->and($event->subject_type)->toBe('settings')
        ->and($event->changes)->toBe(['deposit_percentage' => ['old' => 50, 'new' => 40]]);
});

test('a central subject, such as a team account, still lands in the agency\'s own log, not the platform\'s', function () {
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $member = User::factory()->role(Role::Staff)->for($tenant)->create();
    actingAs($admin);

    $event = withinTenant($tenant, fn () => auditingFeature()->recordAuditEvent(AuditAction::UserDeactivated, $member));

    expect($event)->toBeInstanceOf(AuditEvent::class)
        ->and($event->actor_id)->toBe($admin->id)
        ->and($event->subject_type)->toBe('user')
        ->and($event->subject_id)->toBe($member->id)
        ->and(PlatformAuditEvent::count())->toBe(0);
});

test('outside an agency nothing is recorded: agency features only run inside one', function () {
    actingAs(User::factory()->role(Role::Admin)->for(tenantOnDomain())->create());

    expect(fn () => auditingFeature()->recordAuditEvent(AuditAction::SettingsUpdated))
        ->toThrow(LogicException::class);
});

test('with nobody signed in the actor is NULL, never 0', function () {
    $tenant = tenantOnDomain();

    $event = withinTenant($tenant, fn () => auditingFeature()->recordAuditEvent(AuditAction::SettingsUpdated, Settings::current()));

    expect($event->actor_id)->toBeNull();
});
