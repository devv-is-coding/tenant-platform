<?php

use App\Models\Settings;
use App\Models\Tenant;
use Illuminate\Support\Facades\URL;

test('a new agency\'s settings read back typed, with the defaults it starts on', function () {
    $settings = withinTenant(Tenant::factory()->create(), fn (): Settings => Settings::current());

    expect($settings->timezone)->toBe('Asia/Manila')
        ->and($settings->booking_hold_minutes)->toBe(1440)
        ->and($settings->deposit_percentage)->toBe(50)
        ->and($settings->cancellation_window_hours)->toBe(48)
        ->and($settings->default_check_in_time)->toBe('14:00:00')
        ->and($settings->requires_listing_approval)->toBeTrue();
});

test('each agency reads and changes only its own settings', function () {
    $agency = Tenant::factory()->create();
    $otherAgency = Tenant::factory()->create();

    withinTenant($agency, fn (): bool => Settings::current()->update(['deposit_percentage' => 30]));

    expect(withinTenant($agency, fn (): int => Settings::current()->deposit_percentage))->toBe(30)
        ->and(withinTenant($otherAgency, fn (): int => Settings::current()->deposit_percentage))->toBe(50);
});

test('an agency\'s logo is served on its own domain, and an agency without one has no logo URL', function () {
    $withLogo = tenantOnDomain();
    $withoutLogo = tenantOnDomain();
    URL::forceRootUrl("http://{$withLogo->id}.localhost");

    $logoUrl = withinTenant($withLogo, function (): ?string {
        Settings::current()->update(['logo_path' => 'branding/logo.png']);

        return Settings::current()->logoUrl();
    });

    expect($logoUrl)->toBe("http://{$withLogo->id}.localhost/tenancy/assets/branding/logo.png")
        ->and(withinTenant($withoutLogo, fn (): ?string => Settings::current()->logoUrl()))->toBeNull();
});
