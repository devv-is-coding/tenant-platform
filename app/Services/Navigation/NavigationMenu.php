<?php

namespace App\Services\Navigation;

use App\Enums\BookingApprovalStatus;
use App\Enums\ItemStatus;
use App\Enums\PlanChangeRequestStatus;
use App\Enums\RefundStatus;
use App\Enums\RestoreRequestStatus;
use App\Models\AuditEvent;
use App\Models\Booking;
use App\Models\Item;
use App\Models\PlanChangeRequest;
use App\Models\Refund;
use App\Models\RestoreRequest;
use App\Models\Settings;
use App\Models\Tenant;
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
 * @phpstan-type NavSection array{label: string, icon: string, href: string|null, current: bool, groups: list<NavGroup>, count: int|null}
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
            return $this->tenantSections($user);
        }

        return [
            ...$this->platformSections($user),
            $this->settingsSection(),
        ];
    }

    /**
     * Get the sections for a staff member on their agency's own domain: the
     * dashboard; for its Managers and Admin an Approvals menu with the
     * decisions waiting on them; and for its Admin an Agency menu with how the agency runs
     * (team, settings, app branding) and its records with the platform
     * (plan and billing, audit log, restore requests).
     *
     * @return list<NavSection>
     */
    private function tenantSections(User $user): array
    {
        $tenant = tenant();
        $running = [];
        $records = [];

        if ($tenant instanceof Tenant && $user->can('viewAgencyTeam', $tenant)) {
            $running[] = $this->link(
                __('Team'),
                __('Your managers and staff, and who can sign in.'),
                route('tenant.team'),
                'user-group',
                'tenant.team',
            );
        }

        if ($user->can('viewAny', Settings::class)) {
            $running[] = $this->link(
                __('Settings'),
                __('Deposits, holds, cancellations and hotel times.'),
                route('tenant.settings'),
                'cog-6-tooth',
                'tenant.settings',
            );
            $running[] = $this->link(
                __('App branding'),
                __('Your app\'s name, colours, logo and support contacts.'),
                route('tenant.branding'),
                'paint-brush',
                'tenant.branding',
            );
        }

        if ($tenant instanceof Tenant && $user->can('viewBilling', $tenant)) {
            $records[] = $this->link(
                __('Plan & billing'),
                __('Your plan, what it includes, invoices and payments.'),
                route('tenant.billing'),
                'credit-card',
                'tenant.billing',
            );
        }

        if ($user->can('viewAny', AuditEvent::class)) {
            $records[] = $this->link(
                __('Audit log'),
                __('Who changed what at the agency, and what the platform did.'),
                route('tenant.audit'),
                'clipboard-document-list',
                'tenant.audit',
            );
        }

        if ($tenant instanceof Tenant && $user->can('viewAgencyRestoreRequests', $tenant)) {
            $records[] = $this->link(
                __('Restore requests'),
                __('Ask the platform to bring back deleted records.'),
                route('tenant.restore-requests'),
                'arrow-uturn-left',
                'tenant.restore-requests',
            );
        }

        $groups = array_values(array_filter([
            $running === [] ? null : $this->group(__('Running the agency'), $running),
            $records === [] ? null : $this->group(__('Records'), $records),
        ]));

        $approvals = $this->approvalLinks($user);

        // Every member of staff sees the bookings; the count is the decisions
        // waiting on those who take them.
        $bookings = $user->can('viewAny', Booking::class)
            ? [$this->section(__('Bookings'), 'ticket', route('tenant.bookings'), 'tenant.bookings', $this->bookingsAwaitingDecision($user))]
            : [];

        return [
            $this->section(__('Dashboard'), 'squares-2x2', route('tenant.dashboard'), 'tenant.dashboard'),
            ...$bookings,
            ...($approvals === [] ? [] : [$this->dropdown(__('Approvals'), 'check-badge', [$this->group(null, $approvals)], count: $this->waitingDecisionCount($user))]),
            ...($groups === [] ? [] : [$this->dropdown(__('Agency'), 'building-office', $groups)]),
        ];
    }

    /**
     * Get the queues of decisions the user takes for the agency (TM): for
     * its Managers and its Admin.
     *
     * @return list<NavLink>
     */
    private function approvalLinks(User $user): array
    {
        $links = [];

        if ($user->can('viewApprovalQueue', Item::class)) {
            $links[] = $this->link(
                __('Listing approvals'),
                __('Listings staff sent for approval, and approved ones to publish.'),
                route('tenant.listing-approvals'),
                'clipboard-document-check',
                'tenant.listing-approvals',
            );
        }

        if ($user->can('viewAny', Refund::class)) {
            $links[] = $this->link(
                __('Refunds'),
                __('Money customers asked to have back, and approved refunds to pay out.'),
                route('tenant.refunds'),
                'receipt-refund',
                'tenant.refunds',
            );
        }

        return $links;
    }

    /**
     * Count the bookings waiting for the user's decision, shown beside
     * Bookings; none for staff, who do not decide them.
     */
    private function bookingsAwaitingDecision(User $user): ?int
    {
        $booking = new Booking;

        if (! $user->can('decide', $booking) && ! $user->can('cancel', $booking)) {
            return null;
        }

        return Booking::query()->where('approval_status', BookingApprovalStatus::Pending)->count();
    }

    /**
     * Count the decisions waiting on the user, shown beside Approvals: the
     * listings sent for approval and the refunds asked for.
     */
    private function waitingDecisionCount(User $user): int
    {
        $count = 0;

        if ($user->can('viewApprovalQueue', Item::class)) {
            $count += Item::query()->where('item_status', ItemStatus::Pending)->count();
        }

        if ($user->can('viewAny', Refund::class)) {
            $count += Refund::query()->where('status', RefundStatus::Requested)->count();
        }

        return $count;
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

        // Every daily destination sits on the top bar (UI-4): agencies, billing,
        // the requests waiting on a decision, with their count, and the log.
        return [
            $this->section(__('Dashboard'), 'squares-2x2', route('admin.dashboard'), 'admin.dashboard'),
            $this->dropdown(__('Agencies'), 'building-office-2', [
                $this->group(null, [
                    $this->link(
                        __('All agencies'),
                        __('Search agencies and open their records.'),
                        route('admin.tenants.index'),
                        'building-office-2',
                        'admin.tenants.index',
                    ),
                    $this->link(
                        __('Onboard an agency'),
                        __('Create an agency and its first admin.'),
                        route('admin.tenants.create'),
                        'building-office',
                        'admin.tenants.create',
                    ),
                ]),
            ], routePattern: 'admin.tenants.*'),
            $this->dropdown(__('Billing'), 'banknotes', [
                $this->group(null, [
                    $this->link(
                        __('Overdue invoices'),
                        __('Agencies still owing after the due date.'),
                        route('admin.invoices.overdue'),
                        'exclamation-triangle',
                        'admin.invoices.overdue',
                    ),
                    $this->link(
                        __('Plans'),
                        __('The price list agencies are put on.'),
                        route('admin.plans.index'),
                        'tag',
                        'admin.plans.index',
                    ),
                ]),
            ]),
            $this->dropdown(__('Requests'), 'inbox-arrow-down', [
                $this->group(null, [
                    $this->link(
                        __('Plan requests'),
                        __('Plans agency admins are asking to be put on.'),
                        route('admin.plan-requests.index'),
                        'arrows-right-left',
                        'admin.plan-requests.index',
                    ),
                    $this->link(
                        __('Restore requests'),
                        __('Deleted records agency admins want back.'),
                        route('admin.restore-requests.index'),
                        'arrow-uturn-left',
                        'admin.restore-requests.index',
                    ),
                ]),
            ], count: $this->pendingRequestCount()),
            $this->section(__('Audit log'), 'clipboard-document-list', route('admin.audit.index'), 'admin.audit.index'),
        ];
    }

    /**
     * Count the plan and restore requests still waiting for the platform's
     * decision, shown beside Requests.
     */
    private function pendingRequestCount(): int
    {
        return PlanChangeRequest::query()->where('status', PlanChangeRequestStatus::Pending)->count()
            + RestoreRequest::query()->where('status', RestoreRequestStatus::Pending)->count();
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
    private function section(string $label, string $icon, string $href, string $routePattern, ?int $count = null): array
    {
        return [
            'label' => $label,
            'icon' => $icon,
            'href' => $href,
            'current' => request()->routeIs($routePattern),
            'groups' => [],
            'count' => $count,
        ];
    }

    /**
     * Build a section that opens a panel. It is current when any of its links
     * is, or when the route matches the given pattern (an agency's own page
     * belongs to Agencies though no link points at it). A count, when given,
     * shows beside the label.
     *
     * @param  list<NavGroup>  $groups
     * @return NavSection
     */
    private function dropdown(string $label, string $icon, array $groups, ?string $routePattern = null, ?int $count = null): array
    {
        $current = $routePattern !== null && request()->routeIs($routePattern);

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
            'count' => $count,
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
