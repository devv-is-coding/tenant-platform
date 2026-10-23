<?php

use App\Enums\PlanTier;
use App\Enums\RestoreRequestStatus;
use App\Enums\Role;
use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\PlatformAuditEvent;
use App\Models\RestoreRequest;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| The super admin's dashboard: an overview of everything the platform holds
| centrally, and what needs a decision. Every figure is read from the central
| database; an agency's own database is never opened here, so there are no
| booking or customer-activity figures.
|
| Recurring revenue is deliberately absent: one honest figure would need
| monthly, quarterly and annual plans normalised first.
|
| Each read re-checks the policy for what it shows, like the other admin pages,
| since Livewire update requests do not re-run the route middleware.
*/
new #[Title('Dashboard')] class extends Component {
    /**
     * How many rows each short list on the dashboard shows.
     */
    private const LIST_LIMIT = 5;

    /**
     * How many recent audit events are shown.
     */
    private const ACTIVITY_LIMIT = 8;

    /**
     * How far ahead the upcoming renewals look, in days.
     */
    private const RENEWAL_WINDOW_DAYS = 7;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Tenant::class);
    }

    /**
     * Count agencies in each status, every status listed even at zero.
     *
     * @return array<int, int> keyed by TenantStatus value
     */
    #[Computed]
    public function agencyCounts(): array
    {
        $this->authorize('viewAny', Tenant::class);

        $counts = Tenant::query()->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(TenantStatus::cases())
            ->mapWithKeys(fn (TenantStatus $status): array => [$status->value => (int) ($counts[$status->value] ?? 0)])
            ->all();
    }

    #[Computed]
    public function totalAgencies(): int
    {
        return array_sum($this->agencyCounts);
    }

    /**
     * Get the newest agencies still waiting to be activated.
     *
     * @return Collection<int, Tenant>
     */
    #[Computed]
    public function pendingAgencies(): Collection
    {
        return $this->newestAgenciesIn(TenantStatus::Pending);
    }

    /**
     * Get the most recently suspended agencies, with their reason.
     *
     * @return Collection<int, Tenant>
     */
    #[Computed]
    public function suspendedAgencies(): Collection
    {
        return $this->newestAgenciesIn(TenantStatus::Suspended);
    }

    /**
     * Count the agency admins' restore requests waiting for a decision.
     */
    #[Computed]
    public function pendingRestoreRequests(): int
    {
        $this->authorize('viewAny', RestoreRequest::class);

        return RestoreRequest::query()->where('status', RestoreRequestStatus::Pending)->count();
    }

    /**
     * Get the subscriptions marked past due, with their agency.
     *
     * @return Collection<int, Subscription>
     */
    #[Computed]
    public function pastDueSubscriptions(): Collection
    {
        $this->authorize('viewAny', Tenant::class);

        return Subscription::query()->with('tenant')->where('status', SubscriptionStatus::PastDue)->orderBy('id')->limit(self::LIST_LIMIT)->get();
    }

    /**
     * Count live subscriptions in each live status.
     *
     * @return array<int, int> keyed by SubscriptionStatus value
     */
    #[Computed]
    public function liveSubscriptionCounts(): array
    {
        $this->authorize('viewAny', Tenant::class);

        $counts = Subscription::query()->toBase()
            ->whereNotNull('active_for_tenant_id')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(SubscriptionStatus::cases())
            ->filter(fn (SubscriptionStatus $status): bool => $status->isLive())
            ->mapWithKeys(fn (SubscriptionStatus $status): array => [$status->value => (int) ($counts[$status->value] ?? 0)])
            ->all();
    }

    /**
     * Count live agencies on each tier, every tier listed even at zero.
     *
     * @return array<int, int> keyed by PlanTier value
     */
    #[Computed]
    public function liveTiers(): array
    {
        $this->authorize('viewAny', Tenant::class);

        $counts = Subscription::query()->toBase()
            ->whereNotNull('active_for_tenant_id')
            ->selectRaw('tier, COUNT(*) as aggregate')
            ->groupBy('tier')
            ->pluck('aggregate', 'tier');

        return collect(PlanTier::cases())
            ->mapWithKeys(fn (PlanTier $tier): array => [$tier->value => (int) ($counts[$tier->value] ?? 0)])
            ->all();
    }

    /**
     * Count live subscriptions on each plan, most common first.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function livePlans(): array
    {
        $this->authorize('viewAny', Tenant::class);

        return Subscription::query()->toBase()
            ->whereNotNull('active_for_tenant_id')
            ->selectRaw('plan_name, COUNT(*) as aggregate')
            ->groupBy('plan_name')
            ->orderByDesc('aggregate')
            ->orderBy('plan_name')
            ->pluck('aggregate', 'plan_name')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * Count agencies with no live subscription at all.
     */
    #[Computed]
    public function agenciesWithoutSubscription(): int
    {
        $this->authorize('viewAny', Tenant::class);

        return Tenant::query()
            ->whereNotIn('id', Subscription::query()->select('active_for_tenant_id')->whereNotNull('active_for_tenant_id'))
            ->count();
    }

    /**
     * Get live subscriptions that renew within the renewal window, soonest first.
     *
     * @return Collection<int, Subscription>
     */
    #[Computed]
    public function upcomingRenewals(): Collection
    {
        $this->authorize('viewAny', Tenant::class);

        return Subscription::query()
            ->with('tenant')
            ->whereNotNull('active_for_tenant_id')
            ->whereDate('next_billing_on', '>=', today())
            ->whereDate('next_billing_on', '<=', today()->addDays(self::RENEWAL_WINDOW_DAYS))
            ->orderBy('next_billing_on')
            ->orderBy('id')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    /**
     * Count active and deactivated users in each agency role, every role listed.
     *
     * Super admins are left out: this is the people the platform serves.
     *
     * @return list<array{label: string, active: int, deactivated: int}>
     */
    #[Computed]
    public function people(): array
    {
        $this->authorize('viewAny', Tenant::class);

        $rows = User::query()->toBase()
            ->where('role', '!=', Role::SuperAdmin)
            ->selectRaw('role, is_active, COUNT(*) as aggregate')
            ->groupBy('role', 'is_active')
            ->get();

        $countOf = fn (Role $role, bool $active): int => (int) $rows
            ->filter(fn (object $row): bool => (int) $row->role === $role->value && (bool) $row->is_active === $active)
            ->sum('aggregate');

        return array_map(fn (array $group): array => [
            'label' => $group[1],
            'active' => $countOf($group[0], true),
            'deactivated' => $countOf($group[0], false),
        ], [
            [Role::Admin, __('Admins')],
            [Role::Manager, __('Managers')],
            [Role::Staff, __('Staff')],
            [Role::Customer, __('Customers')],
        ]);
    }

    /**
     * Get agencies that have no active admin left, a governance gap.
     *
     * @return Collection<int, Tenant>
     */
    #[Computed]
    public function agenciesWithoutAdmin(): Collection
    {
        $this->authorize('viewAny', Tenant::class);

        return Tenant::query()
            ->whereDoesntHave('users', fn (Builder $query) => $query->where('role', Role::Admin)->where('is_active', true))
            ->orderBy('id')
            ->get();
    }

    /**
     * Count the invoices still owed after their due date.
     */
    #[Computed]
    public function overdueInvoiceCount(): int
    {
        $this->authorize('viewAny', SubscriptionInvoice::class);

        return SubscriptionInvoice::query()->overdue(today())->count();
    }

    /**
     * Get what is still owed on overdue invoices.
     */
    #[Computed]
    public function overdueOwed(): Money
    {
        $this->authorize('viewAny', SubscriptionInvoice::class);

        return $this->owedOn(SubscriptionInvoice::query()->overdue(today()));
    }

    /**
     * Get what is still owed on every unsettled invoice, due yet or not.
     */
    #[Computed]
    public function outstandingOwed(): Money
    {
        $this->authorize('viewAny', SubscriptionInvoice::class);

        return $this->owedOn(SubscriptionInvoice::query()->whereIn('status', [SubscriptionInvoiceStatus::Open, SubscriptionInvoiceStatus::PartiallyPaid]));
    }

    /**
     * Get the money received so far this calendar month.
     */
    #[Computed]
    public function receivedThisMonth(): Money
    {
        $this->authorize('viewAny', SubscriptionInvoice::class);

        $receivedMinor = SubscriptionPayment::query()
            ->countingAsPaid()
            ->where('paid_at', '>=', now()->startOfMonth())
            ->where('paid_at', '<=', now())
            ->sum('amount_minor');

        return Money::ofMinor((int) $receivedMinor, config('tenancy.billing_currency'));
    }

    /**
     * Get the most recent payments received, with their agency and invoice.
     *
     * @return Collection<int, SubscriptionPayment>
     */
    #[Computed]
    public function latestPayments(): Collection
    {
        $this->authorize('viewAny', SubscriptionInvoice::class);

        return SubscriptionPayment::query()
            ->with(['tenant', 'invoice'])
            ->countingAsPaid()
            ->latest('paid_at')
            ->latest('id')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, PlatformAuditEvent>
     */
    #[Computed]
    public function recentActivity(): Collection
    {
        $this->authorize('viewAny', PlatformAuditEvent::class);

        return PlatformAuditEvent::query()->latest('created_at')->latest('id')->limit(self::ACTIVITY_LIMIT)->get();
    }

    /**
     * Get the agencies that most recently entered the given status.
     *
     * @return Collection<int, Tenant>
     */
    private function newestAgenciesIn(TenantStatus $status): Collection
    {
        $this->authorize('viewAny', Tenant::class);

        return Tenant::query()
            ->where('status', $status)
            ->latest('status_changed_at')
            ->latest('created_at')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    /**
     * Sum what is still owed on the given invoices, in minor units, by the
     * database. Every invoice is billed in the platform's billing currency.
     *
     * @param  Builder<SubscriptionInvoice>  $invoices
     */
    private function owedOn(Builder $invoices): Money
    {
        $owedMinor = $invoices->selectRaw('COALESCE(SUM(amount_minor - amount_paid_minor), 0) as owed_minor')->value('owed_minor');

        return Money::ofMinor((int) $owedMinor, config('tenancy.billing_currency'));
    }
}; ?>

<div class="flex flex-col gap-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Everything on the platform at a glance, and what needs a decision.') }}</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('admin.tenants.create')" wire:navigate>
            {{ __('Onboard an agency') }}
        </flux:button>
    </div>

    @if ($this->totalAgencies === 0)
        <flux:card class="flex flex-col items-center gap-3 py-12 text-center">
            <flux:icon.building-office-2 class="size-10 text-zinc-400" />
            <flux:heading size="lg">{{ __('No agencies yet') }}</flux:heading>
            <flux:text class="max-w-md">{{ __('Once you onboard the first agency, its status, subscription, billing and activity show up here.') }}</flux:text>
            <flux:button variant="primary" icon="plus" :href="route('admin.tenants.create')" class="mt-2" wire:navigate>
                {{ __('Onboard an agency') }}
            </flux:button>
        </flux:card>
    @else
        {{-- Headline figures --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <a href="{{ route('admin.tenants.index') }}" wire:navigate class="block">
                <flux:card class="h-full transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                    <flux:text>{{ __('Agencies') }}</flux:text>
                    <flux:heading size="xl" class="mt-2">{{ $this->totalAgencies }}</flux:heading>
                    <flux:text size="sm" class="mt-1">{{ __(':count active', ['count' => $this->agencyCounts[TenantStatus::Active->value]]) }}</flux:text>
                </flux:card>
            </a>

            <a href="{{ route('admin.invoices.overdue') }}" wire:navigate class="block">
                <flux:card class="h-full transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                    <flux:text>{{ __('Overdue') }}</flux:text>
                    <flux:heading size="xl" class="mt-2">{{ $this->overdueOwed->format() }}</flux:heading>
                    <flux:text size="sm" class="mt-1">{{ trans_choice(':count overdue invoice|:count overdue invoices', $this->overdueInvoiceCount) }}</flux:text>
                </flux:card>
            </a>

            <flux:card>
                <flux:text>{{ __('Still owed') }}</flux:text>
                <flux:heading size="xl" class="mt-2">{{ $this->outstandingOwed->format() }}</flux:heading>
                <flux:text size="sm" class="mt-1">{{ __('On every unsettled invoice') }}</flux:text>
            </flux:card>

            <flux:card>
                <flux:text>{{ __('Received this month') }}</flux:text>
                <flux:heading size="xl" class="mt-2">{{ $this->receivedThisMonth->format() }}</flux:heading>
                <flux:text size="sm" class="mt-1">{{ now()->format('F Y') }}</flux:text>
            </flux:card>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Agencies by status --}}
            <flux:card class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('Agencies by status') }}</flux:heading>

                <ul class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach (TenantStatus::cases() as $status)
                        <li>
                            <a href="{{ route('admin.tenants.index', ['status' => $status->value]) }}" wire:navigate class="flex items-center justify-between gap-3 py-2 hover:underline">
                                <flux:badge :color="$status->color()" size="sm">{{ $status->label() }}</flux:badge>
                                <span class="font-medium tabular-nums">{{ $this->agencyCounts[$status->value] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </flux:card>

            {{-- Subscriptions --}}
            <flux:card class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('Subscriptions') }}</flux:heading>

                <ul class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->liveSubscriptionCounts as $statusValue => $count)
                        @php($subscriptionStatus = SubscriptionStatus::from($statusValue))
                        <li class="flex items-center justify-between gap-3 py-2">
                            <flux:badge :color="$subscriptionStatus->color()" size="sm">{{ $subscriptionStatus->label() }}</flux:badge>
                            <span class="font-medium tabular-nums">{{ $count }}</span>
                        </li>
                    @endforeach

                    <li class="flex items-center justify-between gap-3 py-2">
                        <flux:text>{{ __('Agencies with no plan') }}</flux:text>
                        <span class="font-medium tabular-nums">{{ $this->agenciesWithoutSubscription }}</span>
                    </li>
                </ul>

                <div>
                    <flux:text size="sm" class="mb-1">{{ __('Live agencies by tier') }}</flux:text>

                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->liveTiers as $tierValue => $count)
                            @php($planTier = PlanTier::from($tierValue))
                            <flux:badge size="sm" :color="$planTier->color()">{{ $planTier->label() }} · {{ $count }}</flux:badge>
                        @endforeach
                    </div>
                </div>

                @if ($this->livePlans)
                    <div>
                        <flux:text size="sm" class="mb-1">{{ __('Plans in use') }}</flux:text>

                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->livePlans as $planName => $count)
                                <flux:badge size="sm" color="zinc">{{ $planName }} · {{ $count }}</flux:badge>
                            @endforeach
                        </div>
                    </div>
                @endif
            </flux:card>

            {{-- People --}}
            <flux:card class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('People') }}</flux:heading>

                <ul class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->people as $group)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <flux:text>{{ $group['label'] }}</flux:text>
                            <span class="text-end">
                                <span class="font-medium tabular-nums">{{ $group['active'] }}</span>
                                @if ($group['deactivated'] > 0)
                                    <flux:text size="sm" class="inline">{{ __('+ :count deactivated', ['count' => $group['deactivated']]) }}</flux:text>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </flux:card>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Needs attention --}}
            <flux:card class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('Needs attention') }}</flux:heading>

                @php($pending = $this->pendingAgencies)
                @php($suspended = $this->suspendedAgencies)

                @if ($pending->isEmpty() && $suspended->isEmpty() && $this->pastDueSubscriptions->isEmpty() && $this->agenciesWithoutAdmin->isEmpty() && $this->pendingRestoreRequests === 0)
                    <flux:text>{{ __('Nothing needs a decision right now.') }}</flux:text>
                @endif

                @if ($this->pendingRestoreRequests > 0)
                    <div>
                        <flux:text size="sm" class="mb-1">{{ __('Restore requests') }}</flux:text>
                        <flux:link :href="route('admin.restore-requests.index')" class="block py-1" wire:navigate>
                            {{ trans_choice(':count request waiting|:count requests waiting', $this->pendingRestoreRequests) }}
                        </flux:link>
                    </div>
                @endif

                @if ($pending->isNotEmpty())
                    <div>
                        <flux:text size="sm" class="mb-1">{{ __('Waiting to be activated') }}</flux:text>
                        @foreach ($pending as $agency)
                            <flux:link :href="route('admin.tenants.show', $agency)" class="block py-1" wire:navigate>{{ $agency->name ?? $agency->id }}</flux:link>
                        @endforeach
                    </div>
                @endif

                @if ($this->pastDueSubscriptions->isNotEmpty())
                    <div>
                        <flux:text size="sm" class="mb-1">{{ __('Subscription past due') }}</flux:text>
                        @foreach ($this->pastDueSubscriptions as $subscription)
                            <flux:link :href="route('admin.tenants.show', $subscription->tenant_id)" class="block py-1" wire:navigate>
                                {{ $subscription->tenant?->name ?? $subscription->tenant_id }} · {{ $subscription->plan_name }}
                            </flux:link>
                        @endforeach
                    </div>
                @endif

                @if ($suspended->isNotEmpty())
                    <div>
                        <flux:text size="sm" class="mb-1">{{ __('Suspended') }}</flux:text>
                        @foreach ($suspended as $agency)
                            <div class="py-1">
                                <flux:link :href="route('admin.tenants.show', $agency)" wire:navigate>{{ $agency->name ?? $agency->id }}</flux:link>
                                @if ($agency->status_reason)
                                    <flux:text size="sm">{{ $agency->status_reason }}</flux:text>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($this->agenciesWithoutAdmin->isNotEmpty())
                    <div>
                        <flux:text size="sm" class="mb-1">{{ __('No active admin') }}</flux:text>
                        @foreach ($this->agenciesWithoutAdmin->take(5) as $agency)
                            <flux:link :href="route('admin.tenants.show', $agency)" class="block py-1" wire:navigate>{{ $agency->name ?? $agency->id }}</flux:link>
                        @endforeach
                    </div>
                @endif
            </flux:card>

            <div class="flex flex-col gap-6">
                {{-- Upcoming renewals --}}
                <flux:card class="flex flex-col gap-4">
                    <flux:heading size="lg" level="2">{{ __('Renewing in the next 7 days') }}</flux:heading>

                    @forelse ($this->upcomingRenewals as $subscription)
                        <div class="flex items-center justify-between gap-3">
                            <flux:link :href="route('admin.tenants.show', $subscription->tenant_id)" wire:navigate>{{ $subscription->tenant?->name ?? $subscription->tenant_id }}</flux:link>
                            <flux:text size="sm" class="text-end">
                                {{ Money::ofMinor($subscription->amount_minor, $subscription->currency)->format() }}
                                · {{ $subscription->next_billing_on?->toFormattedDateString() }}
                            </flux:text>
                        </div>
                    @empty
                        <flux:text>{{ __('No subscription renews this week.') }}</flux:text>
                    @endforelse
                </flux:card>

                {{-- Latest payments --}}
                <flux:card class="flex flex-col gap-4">
                    <flux:heading size="lg" level="2">{{ __('Latest payments') }}</flux:heading>

                    @forelse ($this->latestPayments as $payment)
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <flux:link :href="route('admin.tenants.show', $payment->tenant_id)" wire:navigate>{{ $payment->tenant?->name ?? $payment->tenant_id }}</flux:link>
                                <flux:text size="sm">{{ $payment->invoice->reference }} · {{ $payment->method->label() }}</flux:text>
                            </div>
                            <div class="text-end">
                                <span class="font-medium">{{ Money::ofMinor($payment->amount_minor, $payment->currency)->format() }}</span>
                                <flux:text size="sm">{{ $payment->paid_at?->toFormattedDateString() }}</flux:text>
                            </div>
                        </div>
                    @empty
                        <flux:text>{{ __('No payments have been received yet.') }}</flux:text>
                    @endforelse
                </flux:card>
            </div>
        </div>

        {{-- Recent activity --}}
        <section class="flex flex-col gap-4">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <flux:heading size="lg" level="2">{{ __('Recent activity') }}</flux:heading>

                <flux:link :href="route('admin.audit.index')" variant="subtle" class="text-sm" wire:navigate>
                    {{ __('View the full audit log') }}
                </flux:link>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Action') }}</flux:table.column>
                    <flux:table.column class="max-md:hidden">{{ __('Agency') }}</flux:table.column>
                    <flux:table.column class="max-sm:hidden">{{ __('By') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('When') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->recentActivity as $event)
                        <flux:table.row :key="$event->id">
                            <flux:table.cell class="align-top">
                                <span class="font-medium text-zinc-800 dark:text-white">{{ $event->action->label() }}</span>
                                @foreach ($event->changeSummary() as $change)
                                    <flux:text size="sm" class="font-mono">{{ $change }}</flux:text>
                                @endforeach
                            </flux:table.cell>
                            <flux:table.cell class="align-top max-md:hidden">{{ $event->tenant_label ?? __('Platform') }}</flux:table.cell>
                            <flux:table.cell class="align-top max-sm:hidden">{{ $event->actor_label ?? __('System') }}</flux:table.cell>
                            <flux:table.cell align="end" class="align-top whitespace-nowrap">
                                <time datetime="{{ $event->created_at?->toIso8601String() }}" title="{{ $event->created_at?->toDayDateTimeString() }}">{{ $event->created_at?->diffForHumans() }}</time>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="py-8 text-center">{{ __('No platform activity has been recorded yet.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </section>
    @endif
</div>
