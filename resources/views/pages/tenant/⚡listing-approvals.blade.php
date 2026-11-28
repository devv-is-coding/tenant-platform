<?php

use App\Actions\Catalog\ListingApprovalAction;
use App\Enums\ItemStatus;
use App\Enums\PlanFeature;
use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| The manager's listing approvals (TM-1): listings staff sent for approval,
| approved or rejected here with a reason the writer sees, and approved ones
| waiting to be published. Approving and publishing are separate steps, so a
| listing goes on sale only when someone chooses to put it there.
|
| Each action authorizes again, as does ListingApprovalAction, because
| Livewire's update requests do not re-run the route's middleware.
*/
new #[Title('Listing approvals')] class extends Component {
    /**
     * The number of decided listings listed, most recently changed first.
     */
    private const RECENTLY_DECIDED_LIMIT = 10;

    /**
     * The listing the approve, reject or publish confirmation is open for.
     * Set only by the confirm methods, never by the browser.
     */
    #[Locked]
    public ?int $approving_id = null;

    #[Locked]
    public ?int $rejecting_id = null;

    #[Locked]
    public ?int $publishing_id = null;

    public string $rejection_reason = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewApprovalQueue', Item::class);
    }

    /**
     * Open the approve confirmation, which shows what the listing sells.
     */
    public function confirmApprove(int $id): void
    {
        $item = Item::query()->findOrFail($id);
        $this->authorize('decide', $item);

        $this->resetErrorBag();
        $this->approving_id = $item->id;
        unset($this->approving);

        Flux::modal('approve-listing')->show();
    }

    /**
     * Approve the listing the confirmation is open for.
     */
    public function approve(ListingApprovalAction $workflow): void
    {
        $item = Item::query()->findOrFail($this->approving_id);
        $this->authorize('decide', $item);

        $workflow->approve(Auth::user(), $item);

        $this->closeModals('approving_id');
        Flux::toast(variant: 'success', text: __(':item approved. Publish it to put it on sale.', ['item' => $item->name]));
    }

    /**
     * Open the reject form for a waiting or approved listing.
     */
    public function confirmReject(int $id): void
    {
        $item = Item::query()->findOrFail($id);
        $this->authorize('decide', $item);

        $this->resetErrorBag();
        $this->reset('rejection_reason');
        $this->rejecting_id = $item->id;

        Flux::modal('reject-listing')->show();
    }

    /**
     * Send the listing the form is open for back to its writer, with a reason.
     */
    public function reject(ListingApprovalAction $workflow): void
    {
        $item = Item::query()->findOrFail($this->rejecting_id);
        $this->authorize('decide', $item);

        $workflow->reject(Auth::user(), $item, $this->only('rejection_reason'));

        $this->closeModals('rejecting_id', 'rejection_reason');
        Flux::toast(variant: 'success', text: __(':item was sent back to its writer.', ['item' => $item->name]));
    }

    /**
     * Open the publish confirmation for an approved listing.
     */
    public function confirmPublish(int $id): void
    {
        $item = Item::query()->findOrFail($id);
        $this->authorize('publish', $item);

        $this->resetErrorBag();
        $this->publishing_id = $item->id;
        unset($this->publishing);

        Flux::modal('publish-listing')->show();
    }

    /**
     * Put the listing the confirmation is open for on sale.
     */
    public function publish(ListingApprovalAction $workflow): void
    {
        $item = Item::query()->findOrFail($this->publishing_id);
        $this->authorize('publish', $item);

        $workflow->publish(Auth::user(), $item);

        $this->closeModals('publishing_id');
        Flux::toast(variant: 'success', text: __(':item is on sale.', ['item' => $item->name]));
    }

    /**
     * Get the agency of the current request.
     */
    #[Computed]
    public function agency(): Tenant
    {
        $agency = tenant();

        abort_unless($agency instanceof Tenant, 404);

        return $agency;
    }

    /**
     * Get the listing the approve confirmation is open for, with what it sells.
     */
    #[Computed]
    public function approving(): ?Item
    {
        return $this->approving_id === null ? null : Item::query()->with('options')->find($this->approving_id);
    }

    /**
     * Get the listing the publish confirmation is open for.
     */
    #[Computed]
    public function publishing(): ?Item
    {
        return $this->publishing_id === null ? null : Item::query()->find($this->publishing_id);
    }

    /**
     * Get the listings waiting for a decision, longest waiting first.
     *
     * @return Collection<int, Item>
     */
    #[Computed]
    public function pending(): Collection
    {
        $this->authorize('viewApprovalQueue', Item::class);

        return Item::query()->where('item_status', ItemStatus::Pending)->oldest('submitted_at')->oldest('id')->get();
    }

    /**
     * Get the approved listings not yet on sale, longest approved first.
     *
     * @return Collection<int, Item>
     */
    #[Computed]
    public function approved(): Collection
    {
        $this->authorize('viewApprovalQueue', Item::class);

        return Item::query()->where('item_status', ItemStatus::Approved)->oldest('approved_at')->oldest('id')->get();
    }

    /**
     * Get the listings most recently published or sent back.
     *
     * @return Collection<int, Item>
     */
    #[Computed]
    public function recentlyDecided(): Collection
    {
        $this->authorize('viewApprovalQueue', Item::class);

        return Item::query()
            ->whereIn('item_status', [ItemStatus::Published, ItemStatus::Rejected])
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::RECENTLY_DECIDED_LIMIT)
            ->get();
    }

    /**
     * Get the names of the people who wrote and decided the listings shown,
     * by id, in one query: they are central users, so not a relation here.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function people(): array
    {
        $ids = $this->pending->concat($this->approved)->concat($this->recentlyDecided)
            ->flatMap(fn (Item $item): array => [$item->created_by, $item->approved_by])
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $ids === [] ? [] : User::query()->whereKey($ids)->get()->mapWithKeys(fn (User $user): array => [$user->id => $user->full_name])->all();
    }

    /**
     * Close the open confirmation, forget what it was for, and reload the lists.
     */
    private function closeModals(string ...$properties): void
    {
        $this->reset(...$properties);
        $this->resetErrorBag();
        unset($this->pending, $this->approved, $this->recentlyDecided, $this->people, $this->approving, $this->publishing);
        Flux::modals()->close();
    }
}; ?>

<div class="flex flex-col gap-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Listing approvals') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Listings your staff sent for approval. Approving keeps a listing internal; publishing puts it on sale in the app.') }}</flux:text>
    </div>

    <x-tenant.read-only-notice :agency="$this->agency" />

    @if (! $this->agency->status->isReadOnly())
        @unlessfeature(PlanFeature::Catalog)
            <x-tenant.feature-locked :feature="PlanFeature::Catalog" />
        @endfeature
    @endif

    <section class="flex flex-col gap-4">
        <flux:heading size="lg" level="2">{{ __('Waiting for a decision') }}</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Listing') }}</flux:table.column>
                <flux:table.column class="max-md:hidden">{{ __('Type') }}</flux:table.column>
                <flux:table.column class="max-lg:hidden">{{ __('Sent back before') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->pending as $item)
                    <flux:table.row :key="$item->id">
                        <flux:table.cell>
                            <span class="font-medium">{{ $item->name }}</span>
                            <flux:text size="sm">{{ $this->people[$item->created_by] ?? '—' }} · {{ $item->submitted_at?->diffForHumans() }}</flux:text>
                        </flux:table.cell>

                        <flux:table.cell class="max-md:hidden">{{ $item->item_type->label() }}</flux:table.cell>

                        <flux:table.cell class="max-lg:hidden whitespace-normal">{{ $item->rejection_reason ?? '—' }}</flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-1">
                                @can('decide', $item)
                                    <flux:button size="xs" variant="primary" inset="top bottom" wire:click="confirmApprove({{ $item->id }})">{{ __('Approve') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" inset="top bottom" wire:click="confirmReject({{ $item->id }})">{{ __('Reject') }}</flux:button>
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="py-12 text-center">{{ __('No listings are waiting for a decision.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <section class="flex flex-col gap-4">
        <flux:heading size="lg" level="2">{{ __('Approved, not on sale yet') }}</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Listing') }}</flux:table.column>
                <flux:table.column class="max-md:hidden">{{ __('Type') }}</flux:table.column>
                <flux:table.column class="max-lg:hidden">{{ __('Approved') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->approved as $item)
                    <flux:table.row :key="$item->id">
                        <flux:table.cell>
                            <span class="font-medium">{{ $item->name }}</span>
                            <flux:text size="sm">{{ $this->people[$item->created_by] ?? '—' }}</flux:text>
                        </flux:table.cell>

                        <flux:table.cell class="max-md:hidden">{{ $item->item_type->label() }}</flux:table.cell>

                        <flux:table.cell class="max-lg:hidden">
                            {{ $item->approved_at?->toFormattedDateString() }}
                            <flux:text size="sm">{{ $this->people[$item->approved_by] ?? '—' }}</flux:text>
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-1">
                                @can('publish', $item)
                                    <flux:button size="xs" variant="primary" inset="top bottom" wire:click="confirmPublish({{ $item->id }})">{{ __('Publish') }}</flux:button>
                                @endcan
                                @can('decide', $item)
                                    <flux:button size="xs" variant="ghost" inset="top bottom" wire:click="confirmReject({{ $item->id }})">{{ __('Reject') }}</flux:button>
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="py-12 text-center">{{ __('Every approved listing is on sale.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    @if ($this->recentlyDecided->isNotEmpty())
        <section class="flex flex-col gap-4">
            <flux:heading size="lg" level="2">{{ __('Recently decided') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Listing') }}</flux:table.column>
                    <flux:table.column>{{ __('Decision') }}</flux:table.column>
                    <flux:table.column class="max-md:hidden">{{ __('By') }}</flux:table.column>
                    <flux:table.column class="max-md:hidden">{{ __('On') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->recentlyDecided as $item)
                        <flux:table.row :key="$item->id">
                            <flux:table.cell>{{ $item->name }}</flux:table.cell>
                            <flux:table.cell class="whitespace-normal">
                                <flux:badge :color="$item->item_status->color()" size="sm" inset="top bottom">{{ $item->item_status->label() }}</flux:badge>
                                @if ($item->item_status === ItemStatus::Rejected && $item->rejection_reason)
                                    <flux:text size="sm">{{ $item->rejection_reason }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden">{{ $this->people[$item->approved_by] ?? '—' }}</flux:table.cell>
                            <flux:table.cell class="max-md:hidden">{{ ($item->item_status === ItemStatus::Rejected ? $item->rejected_at : $item->published_at)?->toFormattedDateString() }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    @endif

    <flux:modal name="approve-listing" class="md:w-[32rem]">
        @if ($this->approving)
            <form wire:submit="approve" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Approve :item?', ['item' => $this->approving->name]) }}</flux:heading>
                    <flux:text class="mt-2">{{ __('It stays off sale until it is published.') }}</flux:text>
                </div>

                @if ($this->approving->description)
                    <flux:text class="whitespace-pre-line">{{ $this->approving->description }}</flux:text>
                @endif

                <div>
                    <flux:text size="sm" class="mb-2 font-medium">{{ __('What it sells') }}</flux:text>
                    <ul class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($this->approving->options as $option)
                            <li class="flex items-center justify-between gap-3 py-2" wire:key="option-{{ $option->id }}">
                                <flux:text>{{ $option->name }}</flux:text>
                                <span class="font-medium">{{ $option->price->format() }}</span>
                            </li>
                        @empty
                            <li class="py-2"><flux:text>{{ __('No options yet, so nothing can be booked on it.') }}</flux:text></li>
                        @endforelse
                    </ul>
                </div>

                <flux:error name="status" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary">{{ __('Approve') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

    <flux:modal name="reject-listing" class="md:w-[28rem]">
        <form wire:submit="reject" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Send this listing back?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('It goes off the queue and back to its writer, who can edit it and send it again. They see your reason, and it is kept in the audit log.') }}</flux:text>
            </div>

            <flux:textarea wire:model="rejection_reason" :label="__('What to change')" rows="3" required />

            <flux:error name="status" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="danger">{{ __('Reject') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="publish-listing" class="md:w-[28rem]">
        @if ($this->publishing)
            <form wire:submit="publish" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Put :item on sale?', ['item' => $this->publishing->name]) }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Customers see it in the app straight away and can book it.') }}</flux:text>
                </div>

                <flux:error name="status" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary">{{ __('Publish') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
