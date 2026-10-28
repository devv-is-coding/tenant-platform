<?php

use App\Actions\Agency\UpdateAgencyBrandingAction;
use App\Enums\AuditAction;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\AuditEvent;
use App\Models\Settings;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Open the agency's branding page as the user, from within the agency.
 */
function agencyBranding(Tenant $tenant, User $user): Testable
{
    tenancy()->initialize($tenant);

    return Livewire::actingAs($user)->test('pages::tenant.branding');
}

/**
 * Open the branding page as a new admin of the agency.
 */
function agencyBrandingAsAdmin(Tenant $tenant): Testable
{
    return agencyBranding($tenant, User::factory()->role(Role::Admin)->for($tenant)->create());
}

test('the admin opens the branding page on the agency\'s domain; a manager is refused', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);

    actingAs(User::factory()->role(Role::Admin)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/branding")
        ->assertOk()
        ->assertSee('App branding');

    tenancy()->end();

    actingAs(User::factory()->role(Role::Manager)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/branding")
        ->assertForbidden();
});

test('saving the branding stores it, colours in one form, and records what changed by the signed-in admin', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();

    agencyBranding($tenant, $admin)
        ->set('app_display_name', 'Dumaguete Travel')
        ->set('brand_primary_color', '#0e7490')
        ->set('brand_accent_color', '#F59E0B')
        ->set('support_email', 'help@dgte-travel.test')
        ->set('support_phone', '+63 35 422 0000')
        ->call('save')
        ->assertHasNoErrors();

    $event = AuditEvent::sole();

    expect(Settings::current()->only(['app_display_name', 'brand_primary_color', 'brand_accent_color', 'support_email', 'support_phone']))->toBe([
        'app_display_name' => 'Dumaguete Travel',
        'brand_primary_color' => '#0E7490',
        'brand_accent_color' => '#F59E0B',
        'support_email' => 'help@dgte-travel.test',
        'support_phone' => '+63 35 422 0000',
    ])
        ->and($event->action)->toBe(AuditAction::SettingsUpdated)
        ->and($event->actor_id)->toBe($admin->id)
        ->and($event->changes['brand_primary_color'])->toBe(['old' => null, 'new' => '#0E7490']);
});

test('an emptied field goes back to the platform default, and a colour typed in another case is no change', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    withinTenant($tenant, fn () => Settings::current()->update(['app_display_name' => 'Old Name', 'brand_primary_color' => '#0E7490']));

    agencyBrandingAsAdmin($tenant)
        ->set('app_display_name', '  ')
        ->set('brand_primary_color', '#0e7490')
        ->call('save');

    expect(Settings::current()->app_display_name)->toBeNull()
        ->and(AuditEvent::sole()->changes)->toBe(['app_display_name' => ['old' => 'Old Name', 'new' => null]]);
});

test('a colour that is not a six-digit hex code is refused', function (string $color) {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);

    agencyBrandingAsAdmin($tenant)
        ->set('brand_primary_color', $color)
        ->call('save')
        ->assertHasErrors(['brand_primary_color' => 'Enter the colour as a hex code, such as #0E7490.']);

    expect(Settings::current()->brand_primary_color)->toBeNull();
})->with([
    'no hash' => '0E7490',
    'too short' => '#0E749',
    'not hex' => '#GGGGGG',
    'a colour name' => 'red',
    'CSS injected after a colour' => '#0E7490; background: url(x)',
]);

test('the logo is stored on the agency\'s own disk, served on its domain, and absent from another agency\'s', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $other = tenantOnDomain();

    agencyBrandingAsAdmin($tenant)
        ->set('logo', UploadedFile::fake()->image('logo.png', 256, 256))
        ->call('save')
        ->assertHasNoErrors();

    $path = Settings::current()->logo_path;

    expect($path)->toStartWith('branding/')
        ->and(Storage::disk(UpdateAgencyBrandingAction::LOGO_DISK)->path($path))->toContain("tenant{$tenant->id}")
        ->and(Storage::disk(UpdateAgencyBrandingAction::LOGO_DISK)->exists($path))->toBeTrue()
        ->and(withinTenant($other, fn (): bool => Storage::disk(UpdateAgencyBrandingAction::LOGO_DISK)->exists($path)))->toBeFalse();

    tenancy()->end();

    actingAs(User::factory()->role(Role::Admin)->for($tenant)->create())
        ->get("http://{$tenant->id}.localhost/tenancy/assets/{$path}")
        ->assertOk();
});

test('a new logo replaces the old one, whose file is removed', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $page = agencyBrandingAsAdmin($tenant)
        ->set('logo', UploadedFile::fake()->image('first.png'))
        ->call('save');
    $firstPath = Settings::current()->logo_path;

    $page->set('logo', UploadedFile::fake()->image('second.png'))->call('save');

    expect(Settings::current()->logo_path)->not->toBe($firstPath)
        ->and(Storage::disk(UpdateAgencyBrandingAction::LOGO_DISK)->exists($firstPath))->toBeFalse();
});

test('removing the logo clears it and its file', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    $page = agencyBrandingAsAdmin($tenant)
        ->set('logo', UploadedFile::fake()->image('logo.png'))
        ->call('save');
    $path = Settings::current()->logo_path;

    $page->set('remove_logo', true)->call('save');

    expect(Settings::current()->logo_path)->toBeNull()
        ->and(Storage::disk(UpdateAgencyBrandingAction::LOGO_DISK)->exists($path))->toBeFalse();
});

test('a logo that is not a small raster image is refused', function (Closure $makeFile) {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);

    agencyBrandingAsAdmin($tenant)
        ->set('logo', $makeFile())
        ->call('save')
        ->assertHasErrors('logo');

    expect(Settings::current()->logo_path)->toBeNull();
})->with([
    'an SVG, which can carry script' => [fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
    'a PDF' => [fn () => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')],
    'over 1 MB' => [fn () => UploadedFile::fake()->image('logo.png')->size(1025)],
]);

test('without Branded app the saved branding is shown and kept, but cannot be changed', function () {
    $tenant = tenantOnDomain();
    withinTenant($tenant, fn () => Settings::current()->update(['app_display_name' => 'Kept Name']));

    agencyBrandingAsAdmin($tenant)
        ->assertSee('Available on Basic')
        ->assertSee('Kept Name')
        ->assertDontSeeHtml('data-test="save-branding-button"')
        ->set('app_display_name', 'New Name')
        ->call('save')
        ->assertForbidden();

    expect(Settings::current()->app_display_name)->toBe('Kept Name');
});

test('a read-only agency\'s branding cannot be changed', function () {
    $tenant = tenantOnDomain(TenantStatus::Suspended, PlanTier::Pro);

    agencyBrandingAsAdmin($tenant)
        ->assertSee('This agency is suspended.')
        ->set('app_display_name', 'New Name')
        ->call('save')
        ->assertForbidden();

    expect(Settings::current()->app_display_name)->toBeNull();
});

test('the branding component refuses anyone but the agency\'s admin, without the route middleware', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);

    agencyBranding($tenant, User::factory()->role(Role::Staff)->for($tenant)->create())->assertForbidden();
});

test('the saved name is escaped in the preview', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    withinTenant($tenant, fn () => Settings::current()->update(['app_display_name' => '<script>alert(1)</script>']));

    agencyBrandingAsAdmin($tenant)
        ->assertSee('&lt;script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});
