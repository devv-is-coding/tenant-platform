<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\ValueObjects\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Agencies')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    /**
     * The status filter's value for deleted agencies, which every other filter
     * leaves out.
     */
    private const DELETED = 'deleted';

    /**
     * A TenantStatus value, "deleted", or empty for every live agency. Kept as
     * a string because that is what the select and the query string hand back.
     */
    #[Url(except: '')]
    public string $status = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Tenant::class);
    }

    /**
     * Return to the first page when the search changes, since the current page
     * may not exist in the narrower result set.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Return to the first page when the status filter changes.
     */
    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Get one page of the directory.
     *
     * Authorized again here because every search, filter and page change is a
     * Livewire update request, which does not re-run the console's route
     * middleware.
     *
     * @return LengthAwarePaginator<int, Tenant>
     */
    #[Computed]
    public function tenants(): LengthAwarePaginator
    {
        $this->authorize('viewAny', Tenant::class);

        $search = trim($this->search);

        $deleted = $this->status === self::DELETED;

        return Tenant::query()
            // A deleted agency's domains were deleted with it; show them anyway.
            ->with(['domains' => fn ($query) => $query->when($deleted, fn ($query) => $query->withTrashed())])
            ->with('liveSubscription')
            ->withCount('users')
            ->when($deleted, fn (Builder $query) => $query->onlyTrashed())
            ->when($search !== '', fn (Builder $query) => $query->search($search))
            ->when(TenantStatus::tryFrom((int) $this->status), fn (Builder $query, TenantStatus $status) => $query->where('status', $status))
            ->latest('created_at')
            ->orderBy('id')
            ->paginate(15);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Agencies') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Every agency on the platform, newest first.') }}</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('admin.tenants.create')" wire:navigate>
            {{ __('Onboard an agency') }}
        </flux:button>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search by name, id or domain')"
            :aria-label="__('Search agencies')"
            clearable
            class="sm:max-w-sm"
        />

        <flux:select wire:model.live="status" :aria-label="__('Filter by status')" class="sm:max-w-48">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>

            @foreach (TenantStatus::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach

            <flux:select.option value="deleted">{{ __('Deleted') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:table :paginate="$this->tenants">
        <flux:table.columns>
            <flux:table.column>{{ __('Agency') }}</flux:table.column>
            <flux:table.column class="max-md:hidden">{{ __('Domain') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Tier') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden">{{ __('Plan') }}</flux:table.column>
            <flux:table.column class="max-md:hidden">{{ __('Billing') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden" align="end">{{ __('Users') }}</flux:table.column>
            <flux:table.column class="max-lg:hidden">{{ __('Onboarded') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->tenants as $tenant)
                <flux:table.row :key="$tenant->id">
                    <flux:table.cell>
                        <flux:link :href="route('admin.tenants.show', $tenant)" variant="ghost" class="font-medium" wire:navigate>
                            {{ $tenant->name ?? $tenant->id }}
                        </flux:link>

                        <flux:text size="sm">{{ $tenant->id }}</flux:text>
                    </flux:table.cell>

                    <flux:table.cell class="max-md:hidden">{{ $tenant->domains->pluck('domain')->join(', ') }}</flux:table.cell>

                    <flux:table.cell>
                        @if ($tenant->trashed())
                            <flux:badge color="red" size="sm" inset="top bottom">{{ __('Deleted') }}</flux:badge>
                        @else
                            <flux:badge :color="$tenant->status->color()" size="sm" inset="top bottom">{{ $tenant->status->label() }}</flux:badge>
                        @endif
                    </flux:table.cell>

                    @php($subscription = $tenant->liveSubscription)

                    <flux:table.cell>
                        @if ($subscription)
                            <flux:badge :color="$subscription->tier->color()" size="sm" inset="top bottom">{{ $subscription->tier->label() }}</flux:badge>
                        @else
                            <flux:text size="sm">{{ __('No plan') }}</flux:text>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell class="max-sm:hidden">
                        @if ($subscription)
                            <div class="flex flex-col gap-1 py-1">
                                <span class="font-medium text-zinc-800 dark:text-white">{{ $subscription->plan_name }}</span>
                                <flux:text size="sm" class="tabular-nums">
                                    {{ Money::ofMinor($subscription->amount_minor, $subscription->currency)->format() }}
                                    <span aria-hidden="true" class="mx-1">·</span>
                                    {{ $subscription->interval->label() }}
                                </flux:text>
                            </div>
                        @else
                            <span aria-hidden="true" class="text-zinc-400">—</span>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell class="max-md:hidden">
                        @if ($subscription)
                            <flux:badge :color="$subscription->status->color()" size="sm" inset="top bottom">{{ $subscription->status->label() }}</flux:badge>
                        @else
                            <span aria-hidden="true" class="text-zinc-400">—</span>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell class="max-sm:hidden" align="end">{{ $tenant->users_count }}</flux:table.cell>

                    <flux:table.cell class="max-lg:hidden">{{ $tenant->created_at?->toFormattedDateString() }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8" class="py-12 text-center">
                        {{ $search === '' && $status === '' ? __('No agencies have been onboarded yet.') : __('No agencies match your search.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
