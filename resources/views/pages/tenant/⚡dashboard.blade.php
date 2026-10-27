<?php

use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| The agency's dashboard: its own side of the super admin's, with the same
| layout, cards and "Needs attention" list. Every role sees only the cards it
| can act on: the plan, the team and what needs a decision are the Admin's.
| Later items add their own cards here (bookings, the past-due banner,
| restore requests) rather than separate pages.
|
| Everything shown so far is central: the agency's status, its subscription
| and its staff. No customer details or money figures are shown, so a closed
| agency has nothing to mask here yet; a card that adds either wraps it in
| <x-tenant.masked>.
|
| Each read re-checks the policy for what it shows, since Livewire update
| requests do not re-run the route middleware's role checks on their own.
*/
new #[Title('Dashboard')] class extends Component {
    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAgencyDashboard', $this->currentAgency());
    }

    /**
     * Get the agency the dashboard is for.
     */
    #[Computed]
    public function agency(): Tenant
    {
        $agency = $this->currentAgency();
        $this->authorize('viewAgencyDashboard', $agency);

        return $agency;
    }

    /**
     * Determine if the signed-in user may see the plan card.
     */
    #[Computed]
    public function showsPlan(): bool
    {
        return auth()->user()->can('viewAgencyPlan', $this->agency);
    }

    /**
     * Determine if the signed-in user may see the team card.
     */
    #[Computed]
    public function showsTeam(): bool
    {
        return auth()->user()->can('viewAgencyTeam', $this->agency);
    }

    /**
     * Get the subscription holding the agency's live slot, if it has one.
     */
    #[Computed]
    public function subscription(): ?Subscription
    {
        $this->authorize('viewAgencyPlan', $this->agency);

        return $this->agency->liveSubscription()->first();
    }

    /**
     * Count active and deactivated staff in each staff role, every role listed.
     *
     * @return list<array{label: string, active: int, deactivated: int}>
     */
    #[Computed]
    public function team(): array
    {
        $this->authorize('viewAgencyTeam', $this->agency);

        $rows = $this->agency->staff()->toBase()
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
        ]);
    }

    /**
     * Count the agency's active staff, in every staff role.
     */
    #[Computed]
    public function activeStaff(): int
    {
        return array_sum(array_column($this->team, 'active'));
    }

    /**
     * Get what needs the Admin's attention: the plan, while the agency has
     * none or it is past due.
     *
     * @return list<array{heading: string, text: string}>
     */
    #[Computed]
    public function needsAttention(): array
    {
        $this->authorize('viewAgencyPlan', $this->agency);

        $items = [];

        if ($this->subscription === null) {
            $items[] = [
                'heading' => __('No live plan'),
                'text' => __('The agency is on no plan, so only what every agency has is open to it. Ask the platform to put you on one.'),
            ];
        } elseif ($this->subscription->status === SubscriptionStatus::PastDue) {
            $items[] = [
                'heading' => __('Subscription past due'),
                'text' => __('The platform has not received a payment that was due on :plan. Settle it to keep the agency open.', ['plan' => $this->subscription->plan_name]),
            ];
        }

        return $items;
    }

    /**
     * Get the agency of the current request.
     */
    private function currentAgency(): Tenant
    {
        $agency = tenant();

        abort_unless($agency instanceof Tenant, 404);

        return $agency;
    }
}; ?>

<div class="flex flex-col gap-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => auth()->user()->first_name]) }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('You are signed in as :role of :tenant.', [
                'role' => auth()->user()->role->label(),
                'tenant' => $this->agency->name ?? $this->agency->id,
            ]) }}
        </flux:text>
    </div>

    <x-tenant.read-only-notice :agency="$this->agency" />

    {{-- Headline figures --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card>
            <flux:text>{{ __('Agency status') }}</flux:text>
            <div class="mt-2">
                <flux:badge :color="$this->agency->status->color()">{{ $this->agency->status->label() }}</flux:badge>
            </div>
            @if ($this->agency->status_changed_at)
                <flux:text size="sm" class="mt-2">{{ __('Since :date', ['date' => $this->agency->status_changed_at->toFormattedDateString()]) }}</flux:text>
            @endif
        </flux:card>

        @if ($this->showsPlan)
            <flux:card data-test="plan-card">
                <flux:text>{{ __('Plan') }}</flux:text>
                @if ($this->subscription)
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <flux:badge :color="$this->subscription->tier->color()">{{ $this->subscription->tier->label() }}</flux:badge>
                        <flux:badge size="sm" :color="$this->subscription->status->color()">{{ $this->subscription->status->label() }}</flux:badge>
                    </div>
                    <flux:text size="sm" class="mt-2">{{ $this->subscription->plan_name }}</flux:text>
                @else
                    <flux:heading size="xl" class="mt-2">{{ __('No plan') }}</flux:heading>
                    <flux:text size="sm" class="mt-1">{{ __('Ask the platform to put the agency on one.') }}</flux:text>
                @endif
            </flux:card>
        @endif

        @if ($this->showsTeam)
            <flux:card data-test="team-total-card">
                <flux:text>{{ __('Team') }}</flux:text>
                <flux:heading size="xl" class="mt-2">{{ $this->activeStaff }}</flux:heading>
                <flux:text size="sm" class="mt-1">{{ trans_choice(':count active member|:count active members', $this->activeStaff) }}</flux:text>
            </flux:card>
        @endif
    </div>

    @if ($this->showsPlan || $this->showsTeam)
        <div class="grid gap-6 lg:grid-cols-2">
            @if ($this->showsPlan)
                {{-- Needs attention --}}
                <flux:card class="flex flex-col gap-4" data-test="needs-attention-card">
                    <flux:heading size="lg" level="2">{{ __('Needs attention') }}</flux:heading>

                    @forelse ($this->needsAttention as $item)
                        <div>
                            <flux:text size="sm" class="mb-1">{{ $item['heading'] }}</flux:text>
                            <flux:text class="text-zinc-800 dark:text-white">{{ $item['text'] }}</flux:text>
                        </div>
                    @empty
                        <flux:text>{{ __('Nothing needs a decision right now.') }}</flux:text>
                    @endforelse
                </flux:card>
            @endif

            @if ($this->showsTeam)
                {{-- Team by role --}}
                <flux:card class="flex flex-col gap-4" data-test="team-card">
                    <flux:heading size="lg" level="2">{{ __('Team by role') }}</flux:heading>

                    <ul class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->team as $group)
                            <li class="flex items-center justify-between gap-3 py-2" wire:key="team-{{ $loop->index }}">
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
            @endif
        </div>
    @else
        <flux:card class="flex flex-col items-center gap-3 py-12 text-center">
            <flux:icon.squares-2x2 class="size-10 text-zinc-400" />
            <flux:heading size="lg">{{ __('Nothing to act on yet') }}</flux:heading>
            <flux:text class="max-w-md">{{ __('Your bookings, approvals and catalog work show up here as the agency\'s tools are added.') }}</flux:text>
        </flux:card>
    @endif
</div>
