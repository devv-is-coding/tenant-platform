<?php

use App\Enums\Role;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Fetch the markup of the first dropdown in the navigation.
 */
function firstNavDropdown(string $html): string
{
    preg_match('/<ui-dropdown.*?<\/ui-dropdown>/s', $html, $matches);

    return $matches[0] ?? '';
}

/*
 * ui-dropdown finds its parts by position rather than by attribute: the trigger
 * is the first button in the subtree and the panel must be the last element
 * child carrying [popover]. Breaking either only logs a console warning, and
 * the menu silently stops opening, so the structure is asserted here.
 */
test('a navigation dropdown keeps the structure ui-dropdown requires', function () {
    $html = actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->getContent();

    $dropdown = firstNavDropdown($html);

    expect($dropdown)->not->toBe('')
        ->and($dropdown)->toMatch('/<ui-dropdown[^>]*>\s*<button/')
        ->and($dropdown)->toMatch('/<\/ui-menu>\s*<\/ui-dropdown>$/')
        ->and($dropdown)->toContain('popover=');
});

test('panel rows render a description beneath the label', function () {
    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee(__('Onboard an agency'))
        ->assertSee(__('Create a tenant and its first admin.'));
});

test('the active page is marked for assistive technology, not only visually', function () {
    actingAs(User::factory()->role(Role::Customer)->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('aria-current="page"', escape: false);
});

/*
 * The sidebar survives only as the below-lg drawer, so its absence cannot be
 * asserted directly. These three markers belong to the new shell instead: the
 * horizontal row, its skip link, and the target that link points at.
 */
test('pages render on the navbar shell', function () {
    actingAs(User::factory()->role(Role::Customer)->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-flux-navbar', escape: false)
        ->assertSee('id="main-content"', escape: false)
        ->assertSee(__('Skip to content'));
});
