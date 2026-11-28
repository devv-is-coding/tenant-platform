<?php

use App\Enums\BookingApprovalStatus;
use App\Enums\PlanFeature;
use App\Models\Booking;
use App\Models\Tenant;
use App\Services\Booking\BookingService;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
| The agency's bookings (TM-2): every member of staff sees them; its Managers
| and its Admin approve a booking waiting for them, reject it with a reason
| the customer is given, or cancel one still live. Rejecting and cancelling
| free the booking's units at once; the money is another axis, so whatever
| was paid stays recorded until it is refunded.
|
| Each action authorizes again, because Livewire's update requests do not
| re-run the route's middleware. Customers' details and money figures are
| wrapped in <x-tenant.masked>, and read in the view, never held in a public
| property, so a closed agency's page never sends them.
*/
new #[Title('Bookings')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    /**
     * A BookingApprovalStatus value, or empty for every booking. Kept as a
     * string because that is what the select and the query string hand back;
     * the page opens on the bookings waiting for a decision.
     */
    #[Url(except: '0')]
    public string $status = '0';

    /**
     * The booking the approve, reject or cancel confirmation is open for.
     * Set only by the confirm methods, never by the browser.
     */
    #[Locked]
    public ?int $approving_id = null;

    #[Locked]
    public ?int $rejecting_id = null;

    #[Locked]
    public ?int $cancelling_id = null;

    public string $rejection_reason = '';

    public string $cancellation_reason = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Booking::class);
    }

    /**
     * Return to the first page when the search changes.
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
     * Open the approve confirmation, which shows what the booking holds.
     */
    public function confirmApprove(int $id): void
    {
        $booking = Booking::query()->findOrFail($id);
        $this->authorize('decide', $booking);

        $this->resetErrorBag();
        $this->approving_id = $booking->id;
        unset($this->approving);

        Flux::modal('approve-booking')->show();
    }

    /**
     * Approve the booking the confirmation is open for.
     */
    public function approve(BookingService $bookings): void
    {
        $booking = Booking::query()->findOrFail($this->approving_id);
        $this->authorize('decide', $booking);

        $bookings->approve($booking, Auth::user());

        $this->closeModals('approving_id');
        Flux::toast(variant: 'success', text: __(':reference is confirmed.', ['reference' => $booking->reference]));
    }

    /**
     * Open the reject form for a booking waiting for a decision.
     */
    public function confirmReject(int $id): void
    {
        $booking = Booking::query()->findOrFail($id);
        $this->authorize('decide', $booking);

        $this->resetErrorBag();
        $this->reset('rejection_reason');
        $this->rejecting_id = $booking->id;
        unset($this->rejecting);

        Flux::modal('reject-booking')->show();
    }

    /**
     * Decline the booking the form is open for, with a reason.
     */
    public function reject(BookingService $bookings): void
    {
        $booking = Booking::query()->findOrFail($this->rejecting_id);
        $this->authorize('decide', $booking);

        $bookings->reject($booking, Auth::user(), $this->rejection_reason);

        $this->closeModals('rejecting_id', 'rejection_reason');
        Flux::toast(variant: 'success', text: __(':reference was declined and its units released.', ['reference' => $booking->reference]));
    }

    /**
     * Open the cancel confirmation for a booking still live.
     */
    public function confirmCancel(int $id): void
    {
        $booking = Booking::query()->findOrFail($id);
        $this->authorize('cancel', $booking);

        $this->resetErrorBag();
        $this->reset('cancellation_reason');
        $this->cancelling_id = $booking->id;
        unset($this->cancelling);

        Flux::modal('cancel-booking')->show();
    }

    /**
     * Cancel the booking the confirmation is open for.
     */
    public function cancel(BookingService $bookings): void
    {
        $booking = Booking::query()->findOrFail($this->cancelling_id);
        $this->authorize('cancel', $booking);

        $bookings->cancel($booking, $this->cancellation_reason);

        $this->closeModals('cancelling_id', 'cancellation_reason');
        Flux::toast(variant: 'success', text: __(':reference was cancelled and its units released.', ['reference' => $booking->reference]));
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
     * Get one page of the agency's bookings: those waiting the longest first
     * while that is the filter, the newest first otherwise.
     *
     * @return LengthAwarePaginator<int, Booking>
     */
    #[Computed]
    public function bookings(): LengthAwarePaginator
    {
        $this->authorize('viewAny', Booking::class);

        $search = trim($this->search);
        $status = $this->status === '' ? null : BookingApprovalStatus::tryFrom((int) $this->status);

        return Booking::query()
            ->with('lines')
            ->when($status, fn (Builder $query, BookingApprovalStatus $status) => $query->where('approval_status', $status))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('reference', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('customer_email', 'like', "%{$search}%")))
            ->when($status === BookingApprovalStatus::Pending, fn (Builder $query) => $query->oldest('id'), fn (Builder $query) => $query->latest('id'))
            ->paginate(15);
    }

    /**
     * Get the booking the approve confirmation is open for, with its lines.
     */
    #[Computed]
    public function approving(): ?Booking
    {
        return $this->approving_id === null ? null : Booking::query()->with('lines')->find($this->approving_id);
    }

    /**
     * Get the booking the reject form is open for.
     */
    #[Computed]
    public function rejecting(): ?Booking
    {
        return $this->rejecting_id === null ? null : Booking::query()->find($this->rejecting_id);
    }

    /**
     * Get the booking the cancel confirmation is open for.
     */
    #[Computed]
    public function cancelling(): ?Booking
    {
        return $this->cancelling_id === null ? null : Booking::query()->find($this->cancelling_id);
    }

    /**
     * Close the open confirmation, forget what it was for, and reload the list.
     */
    private function closeModals(string ...$properties): void
    {
        $this->reset(...$properties);
        $this->resetErrorBag();
        unset($this->bookings, $this->approving, $this->rejecting, $this->cancelling);
        Flux::modals()->close();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Bookings') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Every booking customers made with the agency. A new booking holds its units until it is approved, or until its hold runs out.') }}</flux:text>
    </div>

    <x-tenant.read-only-notice :agency="$this->agency" />

    @if (! $this->agency->status->isReadOnly())
        @unlessfeature(PlanFeature::Bookings)
            <x-tenant.feature-locked :feature="PlanFeature::Bookings" />
        @endfeature
    @endif

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search by reference or customer')"
            :aria-label="__('Search bookings')"
            clearable
            class="sm:max-w-sm"
        />

        <flux:select wire:model.live="status" :aria-label="__('Filter by status')" class="sm:max-w-56">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>

            @foreach (BookingApprovalStatus::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$this->bookings">
        <flux:table.columns>
            <flux:table.column>{{ __('Booking') }}</flux:table.column>
            <flux:table.column class="max-md:hidden">{{ __('Customer') }}</flux:table.column>
            <flux:table.column class="max-lg:hidden">{{ __('What') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden" align="end">{{ __('Total') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->bookings as $booking)
                @php($firstLine = $booking->lines->first())
                <flux:table.row :key="$booking->id">
                    <flux:table.cell>
                        <span class="font-medium">{{ $booking->reference }}</span>
                        <flux:text size="sm">{{ $booking->created_at?->diffForHumans() }}</flux:text>
                    </flux:table.cell>

                    <flux:table.cell class="max-md:hidden">
                        <x-tenant.masked>{{ $booking->customer_name }}</x-tenant.masked>
                        <flux:text size="sm"><x-tenant.masked>{{ $booking->customer_email }}</x-tenant.masked></flux:text>
                    </flux:table.cell>

                    <flux:table.cell class="max-lg:hidden whitespace-normal">
                        @if ($firstLine)
                            {{ $firstLine->item_name }} · {{ $firstLine->option_name }}
                            <flux:text size="sm">
                                @if ($firstLine->starts_on)
                                    {{ $firstLine->starts_on->toFormattedDateString() }}@if ($firstLine->ends_on && ! $firstLine->ends_on->equalTo($firstLine->starts_on)) – {{ $firstLine->ends_on->toFormattedDateString() }}@endif
                                @endif
                                @if ($booking->lines->count() > 1)
                                    {{ trans_choice('+ :count more line|+ :count more lines', $booking->lines->count() - 1) }}
                                @endif
                            </flux:text>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell class="max-sm:hidden" align="end">
                        <x-tenant.masked>{{ $booking->total->format() }}</x-tenant.masked>
                    </flux:table.cell>

                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            <flux:badge :color="$booking->approval_status->color()" size="sm" inset="top bottom">{{ $booking->approval_status->label() }}</flux:badge>
                            <flux:badge :color="$booking->payment_status->color()" size="sm" inset="top bottom">{{ $booking->payment_status->label() }}</flux:badge>
                        </div>
                        @if ($booking->approval_status === BookingApprovalStatus::Rejected && $booking->rejection_reason)
                            <flux:text size="sm" class="whitespace-normal">{{ $booking->rejection_reason }}</flux:text>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            @if ($booking->approval_status === BookingApprovalStatus::Pending)
                                @can('decide', $booking)
                                    <flux:button size="xs" variant="primary" inset="top bottom" wire:click="confirmApprove({{ $booking->id }})">{{ __('Approve') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" inset="top bottom" wire:click="confirmReject({{ $booking->id }})">{{ __('Reject') }}</flux:button>
                                @endcan
                            @endif
                            @if ($booking->approval_status->isActive())
                                @can('cancel', $booking)
                                    <flux:button size="xs" variant="ghost" inset="top bottom" wire:click="confirmCancel({{ $booking->id }})">{{ __('Cancel') }}</flux:button>
                                @endcan
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="py-12 text-center">{{ __('No bookings match.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="approve-booking" class="md:w-[32rem]">
        @if ($this->approving)
            <form wire:submit="approve" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Confirm :reference?', ['reference' => $this->approving->reference]) }}</flux:heading>
                    <flux:text class="mt-2">{{ __('The customer is told it is confirmed, and its units stay held with no time limit.') }}</flux:text>
                </div>

                <ul class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->approving->lines as $line)
                        <li class="flex items-center justify-between gap-3 py-2" wire:key="line-{{ $line->id }}">
                            <span>
                                {{ $line->item_name }} · {{ $line->option_name }}
                                <flux:text size="sm">{{ trans_choice(':count unit|:count units', $line->quantity) }}@if ($line->starts_on) · {{ $line->starts_on->toFormattedDateString() }}@endif</flux:text>
                            </span>
                        </li>
                    @endforeach
                </ul>

                <flux:error name="approval" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary">{{ __('Approve') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

    <flux:modal name="reject-booking" class="md:w-[28rem]">
        <form wire:submit="reject" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Decline :reference?', ['reference' => $this->rejecting?->reference]) }}</flux:heading>
                <flux:text class="mt-2">{{ __('Its units are released for others to book. The customer sees your reason, and it is kept in the audit log. Anything already paid stays recorded until you refund it.') }}</flux:text>
            </div>

            <flux:textarea wire:model="rejection_reason" :label="__('Reason')" rows="3" required />

            <flux:error name="approval" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Keep it') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="danger">{{ __('Reject') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="cancel-booking" class="md:w-[28rem]">
        <form wire:submit="cancel" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Cancel :reference?', ['reference' => $this->cancelling?->reference]) }}</flux:heading>
                <flux:text class="mt-2">{{ __('Its units are released for others to book. Anything already paid stays recorded until you refund it.') }}</flux:text>
            </div>

            <flux:textarea wire:model="cancellation_reason" :label="__('Reason (optional)')" :description="__('Kept in the audit log.')" rows="2" />

            <flux:error name="approval" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Keep it') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="danger">{{ __('Cancel booking') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
