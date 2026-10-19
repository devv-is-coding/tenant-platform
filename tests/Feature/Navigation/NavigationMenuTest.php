<?php

use App\Enums\Role;
use App\Models\User;
use App\Services\Navigation\NavigationMenu;

/**
 * Flatten every link href in a menu, so a test can assert on what is reachable
 * without walking the section/group/link nesting itself.
 *
 * @param  list<array<string, mixed>>  $sections
 * @return list<string>
 */
function navigationHrefs(array $sections): array
{
    $hrefs = [];

    foreach ($sections as $section) {
        if ($section['href'] !== null) {
            $hrefs[] = $section['href'];
        }

        foreach ($section['groups'] as $group) {
            foreach ($group['links'] as $link) {
                $hrefs[] = $link['href'];
            }
        }
    }

    return $hrefs;
}

function navigationFor(?User $user, bool $tenantContext = false): array
{
    return app(NavigationMenu::class)->for($user, $tenantContext);
}

test('super admins get the console and agency onboarding, not the customer dashboard', function () {
    $sections = navigationFor(User::factory()->superAdmin()->create());

    expect(navigationHrefs($sections))
        ->toContain(route('admin.dashboard'))
        ->toContain(route('admin.tenants.create'))
        ->not->toContain(route('dashboard'));
});

test('every other central role gets the dashboard and never the console', function (Role $role) {
    $sections = navigationFor(User::factory()->role($role)->create());

    expect(navigationHrefs($sections))
        ->toContain(route('dashboard'))
        ->not->toContain(route('admin.dashboard'))
        ->not->toContain(route('admin.tenants.create'));
})->with([
    'customer' => Role::Customer,
    'admin' => Role::Admin,
    'manager' => Role::Manager,
    'staff' => Role::Staff,
]);

test('central users get the account settings links', function () {
    $sections = navigationFor(User::factory()->role(Role::Customer)->create());

    expect(navigationHrefs($sections))
        ->toContain(route('profile.edit'))
        ->toContain(route('security.edit'))
        ->toContain(route('appearance.edit'));
});

/*
 * route() does not throw across hosts - the central routes carry a literal
 * domain, so route('profile.edit') on a tenant domain returns a central URL
 * instead of failing. These two assertions are the only thing standing between
 * that and a silent cross-domain link in the tenant navigation.
 */
test('tenant context exposes only the tenant dashboard', function () {
    $sections = navigationFor(User::factory()->role(Role::Staff)->create(), tenantContext: true);

    expect($sections)->toHaveCount(1)
        ->and($sections[0]['href'])->toBe(route('tenant.dashboard'));
});

test('tenant context never exposes a central link', function (Role $role) {
    $sections = navigationFor(User::factory()->role($role)->create(), tenantContext: true);

    expect(navigationHrefs($sections))
        ->not->toContain(route('profile.edit'))
        ->not->toContain(route('security.edit'))
        ->not->toContain(route('appearance.edit'))
        ->not->toContain(route('admin.dashboard'))
        ->not->toContain(route('home'));
})->with([
    'admin' => Role::Admin,
    'manager' => Role::Manager,
    'staff' => Role::Staff,
    'super admin' => Role::SuperAdmin,
]);

test('guests get no authenticated links', function () {
    $sections = navigationFor(null);

    expect(navigationHrefs($sections))
        ->toContain(route('home'))
        ->not->toContain(route('dashboard'))
        ->not->toContain(route('admin.dashboard'))
        ->not->toContain(route('profile.edit'));
});

test('a section is marked current when its own route is active', function () {
    $this->get(route('dashboard'));

    $sections = navigationFor(User::factory()->role(Role::Customer)->create());

    expect($sections[0]['current'])->toBeTrue();
});

test('a dropdown is marked current when one of its links is active', function () {
    $this->get(route('profile.edit'));

    $sections = navigationFor(User::factory()->role(Role::Customer)->create());
    $settings = collect($sections)->firstWhere('label', __('Settings'));

    expect($settings['current'])->toBeTrue()
        ->and(collect($settings['groups'][0]['links'])->firstWhere('label', __('Profile'))['current'])->toBeTrue()
        ->and(collect($settings['groups'][0]['links'])->firstWhere('label', __('Security'))['current'])->toBeFalse();
});
