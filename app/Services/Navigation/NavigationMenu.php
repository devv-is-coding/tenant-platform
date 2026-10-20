<?php

namespace App\Services\Navigation;

use App\Models\User;

/**
 * Builds the application's top-level navigation for one request.
 *
 * The same menu renders in three documents - the app shell, the tenant
 * dashboard and the public landing page - so it lives here rather than in a
 * Blade partial: only `app/` is analysed by PHPStan, and role visibility is
 * behaviour that needs tests.
 *
 * @phpstan-type NavLink array{label: string, description: string, href: string, icon: string, current: bool}
 * @phpstan-type NavGroup array{heading: string|null, links: list<NavLink>}
 * @phpstan-type NavSection array{label: string, icon: string, href: string|null, current: bool, groups: list<NavGroup>}
 */
class NavigationMenu
{
    /**
     * Get the navigation sections visible to the given user.
     *
     * The user is passed in rather than read from `auth()`, which is typed as
     * the framework's Authenticatable and would not carry `isSuperAdmin()`.
     *
     * `$tenantContext` comes from `tenancy()->initialized`. It matters because
     * route() does not fail across hosts: the central routes carry a literal
     * domain, so route('profile.edit') on a tenant domain silently returns a
     * central URL rather than throwing. The tenant branch therefore returns
     * early, leaving no path on which a central link can be built there.
     *
     * @return list<NavSection>
     */
    public function for(?User $user, bool $tenantContext): array
    {
        if ($user === null) {
            return $this->guestSections();
        }

        if ($tenantContext) {
            return $this->tenantSections();
        }

        return [
            ...$this->platformSections($user),
            $this->settingsSection(),
        ];
    }

    /**
     * Get the sections for a staff member on their agency's own domain.
     *
     * @return list<NavSection>
     */
    private function tenantSections(): array
    {
        return [
            $this->section(__('Dashboard'), 'home', route('tenant.dashboard'), 'tenant.dashboard'),
        ];
    }

    /**
     * Get the sections for a user on the central domain.
     *
     * @return list<NavSection>
     */
    private function platformSections(User $user): array
    {
        if (! $user->isSuperAdmin()) {
            return [
                $this->section(__('Dashboard'), 'home', route('dashboard'), 'dashboard'),
            ];
        }

        return [
            $this->dropdown(__('Platform'), 'shield-check', [
                $this->group(__('Oversight'), [
                    $this->link(
                        __('Console'),
                        __('Platform-wide status and activity.'),
                        route('admin.dashboard'),
                        'shield-check',
                        'admin.dashboard',
                    ),
                ]),
                $this->group(__('Agencies'), [
                    $this->link(
                        __('All agencies'),
                        __('Search agencies and open their records.'),
                        route('admin.tenants.index'),
                        'building-office-2',
                        'admin.tenants.index',
                    ),
                    $this->link(
                        __('Onboard an agency'),
                        __('Create a tenant and its first admin.'),
                        route('admin.tenants.create'),
                        'building-office',
                        'admin.tenants.create',
                    ),
                ]),
                $this->group(__('Billing'), [
                    $this->link(
                        __('Overdue invoices'),
                        __('Agencies still owing after the due date.'),
                        route('admin.invoices.overdue'),
                        'exclamation-triangle',
                        'admin.invoices.overdue',
                    ),
                ]),
            ]),
        ];
    }

    /**
     * Get the account settings section, which exists only centrally.
     *
     * @return NavSection
     */
    private function settingsSection(): array
    {
        return $this->dropdown(__('Settings'), 'cog', [
            $this->group(null, [
                $this->link(
                    __('Profile'),
                    __('Your name, username and email.'),
                    route('profile.edit'),
                    'user-circle',
                    'profile.edit',
                ),
                $this->link(
                    __('Security'),
                    __('Password, two-factor and passkeys.'),
                    route('security.edit'),
                    'finger-print',
                    'security.edit',
                ),
                $this->link(
                    __('Appearance'),
                    __('Light, dark or system theme.'),
                    route('appearance.edit'),
                    'swatch',
                    'appearance.edit',
                ),
            ]),
        ]);
    }

    /**
     * Get the sections shown to a visitor who is not signed in.
     *
     * @return list<NavSection>
     */
    private function guestSections(): array
    {
        return [
            $this->section(__('Overview'), 'sparkles', route('home'), 'home'),
        ];
    }

    /**
     * Build a section that is a plain link rather than a dropdown.
     *
     * @return NavSection
     */
    private function section(string $label, string $icon, string $href, string $routePattern): array
    {
        return [
            'label' => $label,
            'icon' => $icon,
            'href' => $href,
            'current' => request()->routeIs($routePattern),
            'groups' => [],
        ];
    }

    /**
     * Build a section that opens a panel. It is current when any of its links is.
     *
     * @param  list<NavGroup>  $groups
     * @return NavSection
     */
    private function dropdown(string $label, string $icon, array $groups): array
    {
        $current = false;

        foreach ($groups as $group) {
            foreach ($group['links'] as $link) {
                $current = $current || $link['current'];
            }
        }

        return [
            'label' => $label,
            'icon' => $icon,
            'href' => null,
            'current' => $current,
            'groups' => $groups,
        ];
    }

    /**
     * Build one column within a panel.
     *
     * @param  list<NavLink>  $links
     * @return NavGroup
     */
    private function group(?string $heading, array $links): array
    {
        return ['heading' => $heading, 'links' => $links];
    }

    /**
     * Build one row within a panel column.
     *
     * @return NavLink
     */
    private function link(string $label, string $description, string $href, string $icon, string $routePattern): array
    {
        return [
            'label' => $label,
            'description' => $description,
            'href' => $href,
            'icon' => $icon,
            'current' => request()->routeIs($routePattern),
        ];
    }
}
