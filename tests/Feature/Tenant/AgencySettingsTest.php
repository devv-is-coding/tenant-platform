<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\AuditEvent;
use App\Models\Settings;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Open the agency's settings page as the user, from within the agency.
 */
function agencySettings(Tenant $tenant, User $user): Testable
{
    tenancy()->initialize($tenant);

    return Livewire::actingAs($user)->test('pages::tenant.settings');
}

test('the admin opens the settings page on the agency\'s domain', function () {
    $tenant = tenantOnDomain();

    actingAs(User::factory()->role(Role::Admin)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/settings")
        ->assertOk()
        ->assertSee('Agency settings');
});

test('the form starts from the agency\'s current defaults, times as the time inputs take them', function () {
    $tenant = tenantOnDomain();
    withinTenant($tenant, fn () => Settings::current()->update(['deposit_percentage' => 35, 'default_check_in_time' => '15:30:00']));

    agencySettings($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->assertSet('deposit_percentage', '35')
        ->assertSet('default_check_in_time', '15:30');
});

test('a manager is refused the settings page', function () {
    $tenant = tenantOnDomain();

    actingAs(User::factory()->role(Role::Manager)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/settings")
        ->assertForbidden();
});

test('saving changes the defaults and records what changed from what, by the signed-in admin', function () {
    $tenant = tenantOnDomain();
    withinTenant($tenant, fn () => Settings::current()->update(['deposit_percentage' => 50, 'booking_hold_minutes' => 1440]));
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();

    agencySettings($tenant, $admin)
        ->set('deposit_percentage', '40')
        ->set('booking_hold_minutes', '720')
        ->call('save')
        ->assertHasNoErrors();

    $settings = Settings::current();
    $event = AuditEvent::sole();

    expect($settings->deposit_percentage)->toBe(40)
        ->and($settings->booking_hold_minutes)->toBe(720)
        ->and($event->action)->toBe(AuditAction::SettingsUpdated)
        ->and($event->actor_id)->toBe($admin->id)
        ->and($event->actor_role)->toBe(Role::Admin)
        ->and($event->subject_type)->toBe('settings')
        ->and($event->subject_id)->toBe($settings->id)
        ->and($event->changes)->toBe([
            'booking_hold_minutes' => ['old' => 1440, 'new' => 720],
            'deposit_percentage' => ['old' => 50, 'new' => 40],
        ]);
});

test('every field saves, times in the column\'s own format', function () {
    $tenant = tenantOnDomain();

    agencySettings($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->set('cancellation_window_hours', '72')
        ->set('default_check_in_time', '15:30')
        ->set('default_check_out_time', '11:00')
        ->set('requires_listing_approval', false)
        ->set('timezone', 'Asia/Tokyo')
        ->set('locale', 'fil')
        ->call('save')
        ->assertHasNoErrors();

    expect(Settings::current()->only([
        'cancellation_window_hours', 'default_check_in_time', 'default_check_out_time',
        'requires_listing_approval', 'timezone', 'locale',
    ]))->toBe([
        'cancellation_window_hours' => 72,
        'default_check_in_time' => '15:30:00',
        'default_check_out_time' => '11:00:00',
        'requires_listing_approval' => false,
        'timezone' => 'Asia/Tokyo',
        'locale' => 'fil',
    ]);
});

test('saving without a change records nothing', function () {
    $tenant = tenantOnDomain();

    agencySettings($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->call('save')
        ->assertHasNoErrors();

    expect(AuditEvent::count())->toBe(0);
});

test('a value out of bounds is refused and nothing is saved', function (string $field, string $value) {
    $tenant = tenantOnDomain();

    agencySettings($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->set($field, $value)
        ->call('save')
        ->assertHasErrors($field);

    expect(AuditEvent::count())->toBe(0);
})->with([
    'deposit above 100%' => ['deposit_percentage', '101'],
    'negative deposit' => ['deposit_percentage', '-1'],
    'hold under 15 minutes' => ['booking_hold_minutes', '14'],
    'hold over a week' => ['booking_hold_minutes', '10081'],
    'cancellation window over 30 days' => ['cancellation_window_hours', '721'],
    'fractional hours' => ['cancellation_window_hours', '1.5'],
    'impossible check-in time' => ['default_check_in_time', '25:00'],
    'blank check-out time' => ['default_check_out_time', ''],
    'unknown timezone' => ['timezone', 'Mars/Olympus_Mons'],
    'a language name, not a code' => ['locale', 'English'],
]);

test('the admin is told what the bound is', function () {
    $tenant = tenantOnDomain();

    agencySettings($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->set('deposit_percentage', '101')
        ->call('save')
        ->assertHasErrors(['deposit_percentage' => 'The deposit percentage field must be between 0 and 100.']);
});

test('a read-only agency\'s admin sees the settings but cannot save them', function () {
    $tenant = tenantOnDomain(TenantStatus::Suspended);

    agencySettings($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->assertSee('This agency is suspended.')
        ->assertDontSeeHtml('data-test="save-settings-button"')
        ->set('deposit_percentage', '10')
        ->call('save')
        ->assertForbidden();

    expect(Settings::current()->deposit_percentage)->toBe(50);
});

test('the settings component refuses anyone but the agency\'s admin, without the route middleware', function (Closure $makeUser) {
    $tenant = tenantOnDomain();

    agencySettings($tenant, $makeUser($tenant))->assertForbidden();
})->with([
    'its staff' => [fn (Tenant $tenant) => User::factory()->role(Role::Staff)->for($tenant)->create()],
    'another agency\'s admin' => [fn () => User::factory()->role(Role::Admin)->for(tenantOnDomain())->create()],
]);
