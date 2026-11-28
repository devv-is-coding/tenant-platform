<?php

use App\Enums\PlanFeature;
use App\Enums\RefundStatus;
use App\Models\Refund;
use App\Models\Tenant;
use App\Services\Payment\RefundService;
use App\ValueObjects\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

/*
| The manager's refund decisions (TM-3): refunds customers asked for are
| approved here, in full or for less, or rejected with a reason the customer
| is given; approved ones are then paid out, through Stripe for a card
| payment Stripe knows, by hand for anything else. RefundService keeps the
| money true: never more back than came in.
|
| Deciding needs Bookings; Stripe's step needs StripeRefunds; paying back by
| hand works on every tier. Each action authorizes again, because Livewire's
| update requests do not re-run the route's middleware. Customers' details
| and amounts are read in the view and wrapped in <x-tenant.masked>; the
| amount typed into the approve form is the manager's own.
*/
new #[Title('Refunds')] class extends Component {
    /**
     * The number of closed refunds listed, most recently closed first.
     */
    private const RECENTLY_CLOSED_LIMIT = 10;

    /**
     * The refund the approve, reject or pay-out confirmation is open for.
     * Set only by the confirm methods, never by the browser.
     */
    #[Locked]
    public ?int $approving_id = null;

    #[Locked]
    public ?int $rejecting_id = null;

    #[Locked]
    public ?int $processing_id = null;

    /**
     * The amount to approve, in major units, such as "1500.00".
     */
    public string $amount = '';

    public string $decision_reason = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Refund::class);
    }

    /**
     * Open the approve form, starting at the amount asked for.
     */
    public function confirmApprove(int $id): void
    {
        $refund = Refund::query()->findOrFail($id);
        $this->authorize('decide', $refund);

        $this->resetErrorBag();
        $this->approving_id = $refund->id;
        $this->amount = $refund->amount->toDecimal();
        unset($this->approving);

        Flux::modal('approve-refund')->show();
    }

    /**
     * Approve the refund the form is open for, for the amount typed.
     */
    public function approve(RefundService $refunds): void
    {
        $refund = Refund::query()->findOrFail($this->approving_id);
        $this->authorize('decide', $refund);

        $this->validate(
            ['amount' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/', 'not_regex:/^0*(\.0*)?$/']],
            [
                'amount.regex' => __('Enter the amount as a number with at most two decimal places, without commas.'),
                'amount.not_regex' => __('The amount must be more than zero.'),
            ],
        );

        $approved = $refunds->approve($refund, Auth::user(), Money::parse($this->amount, $refund->currency));

        $this->closeModals('approving_id', 'amount');
        Flux::toast(variant: 'success', text: __(':amount approved. Pay it out when the money goes back.', ['amount' => $approved->amount->format()]));
    }

    /**
     * Open the reject form for a requested refund.
     */
    public function confirmReject(int $id): void
    {
        $refund = Refund::query()->findOrFail($id);
        $this->authorize('decide', $refund);

        $this->resetErrorBag();
        $this->reset('decision_reason');
        $this->rejecting_id = $refund->id;

        Flux::modal('reject-refund')->show();
    }

    /**
     * Reject the refund the form is open for, with a reason.
     */
    public function reject(RefundService $refunds): void
    {
        $refund = Refund::query()->findOrFail($this->rejecting_id);
        $this->authorize('decide', $refund);

        $refunds->reject($refund, Auth::user(), $this->decision_reason);

        $this->closeModals('rejecting_id', 'decision_reason');
        Flux::toast(variant: 'success', text: __('The refund was rejected. The customer is told why.'));
    }

    /**
     * Open the pay-out confirmation for an approved or failed refund.
     */
    public function confirmProcess(int $id): void
    {
        $refund = Refund::query()->findOrFail($id);
        $this->authorize('processByHand', $refund);

        $this->resetErrorBag();
        $this->processing_id = $refund->id;
        unset($this->processing);

        Flux::modal('process-refund')->show();
    }

    /**
     * Pay the refund the confirmation is open for back through Stripe. Stripe
     * may finish at once, fail it, or still be working; each is said.
     */
    public function processThroughStripe(RefundService $refunds): void
    {
        $refund = Refund::query()->with('payment')->findOrFail($this->processing_id);
        $this->authorize('processThroughStripe', $refund);

        try {
            $outcome = $refunds->process($refund);
        } catch (ApiErrorException $refused) {
            report($refused);
            Flux::toast(variant: 'danger', text: __('Stripe did not take the refund. Try again in a moment, or pay it back another way.'));

            return;
        }

        $this->closeModals('processing_id');
        Flux::toast(...match ($outcome->status) {
            RefundStatus::Processed => ['variant' => 'success', 'text' => __(':amount refunded through Stripe.', ['amount' => $outcome->amount->format()])],
            RefundStatus::Failed => ['variant' => 'danger', 'text' => __('Stripe failed the refund. It is still owed: try again, or pay it back another way.')],
            default => ['variant' => 'warning', 'text' => __('Stripe is still working on the refund. It shows as refunded once Stripe confirms it.')],
        });
    }

    /**
     * Record the refund the confirmation is open for as handed back another
     * way, such as in cash.
     */
    public function processByHand(RefundService $refunds): void
    {
        $refund = Refund::query()->findOrFail($this->processing_id);
        $this->authorize('processByHand', $refund);

        $processed = $refunds->processByHand($refund);

        $this->closeModals('processing_id');
        Flux::toast(variant: 'success', text: __(':amount recorded as paid back.', ['amount' => $processed->amount->format()]));
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
     * Get the refunds asked for and waiting for a decision, oldest first.
     *
     * @return Collection<int, Refund>
     */
    #[Computed]
    public function requested(): Collection
    {
        $this->authorize('viewAny', Refund::class);

        return Refund::query()->with('booking')->where('status', RefundStatus::Requested)->oldest('requested_at')->oldest('id')->get();
    }

    /**
     * Get the approved refunds not yet paid out, failed ones included, oldest
     * decision first.
     *
     * @return Collection<int, Refund>
     */
    #[Computed]
    public function toPayOut(): Collection
    {
        $this->authorize('viewAny', Refund::class);

        return Refund::query()->with(['booking', 'payment'])
            ->whereIn('status', [RefundStatus::Approved, RefundStatus::Failed])
            ->oldest('decided_at')
            ->oldest('id')
            ->get();
    }

    /**
     * Get the refunds most recently paid out or rejected.
     *
     * @return Collection<int, Refund>
     */
    #[Computed]
    public function recentlyClosed(): Collection
    {
        $this->authorize('viewAny', Refund::class);

        return Refund::query()->with('booking')
            ->whereIn('status', [RefundStatus::Processed, RefundStatus::Rejected])
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::RECENTLY_CLOSED_LIMIT)
            ->get();
    }

    /**
     * Get the refund the approve form is open for, with its booking.
     */
    #[Computed]
    public function approving(): ?Refund
    {
        return $this->approving_id === null ? null : Refund::query()->with('booking')->find($this->approving_id);
    }

    /**
     * Get how much of the approving refund's booking can still be refunded,
     * this refund aside.
     */
    #[Computed]
    public function refundable(): ?Money
    {
        $refund = $this->approving;

        if ($refund?->booking === null) {
            return null;
        }

        return Money::ofMinor(app(RefundService::class)->refundableMinor($refund->booking, except: $refund), $refund->currency);
    }

    /**
     * Get the refund the pay-out confirmation is open for, with its payment.
     */
    #[Computed]
    public function processing(): ?Refund
    {
        return $this->processing_id === null ? null : Refund::query()->with(['booking', 'payment'])->find($this->processing_id);
    }

    /**
     * Close the open form, forget what it was for, and reload the lists.
     */
    private function closeModals(string ...$properties): void
    {
        $this->reset(...$properties);
        $this->resetErrorBag();
        unset($this->requested, $this->toPayOut, $this->recentlyClosed, $this->approving, $this->refundable, $this->processing);
        Flux::modals()->close();
    }
}; ?>

<div class="flex flex-col gap-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Refunds') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Money customers asked to have back. Approve all of it or less, or reject it with a reason; then pay it out. Never more goes back than came in.') }}</flux:text>
    </div>

    <x-tenant.read-only-notice :agency="$this->agency" />

    @if (! $this->agency->status->isReadOnly())
        @unlessfeature(PlanFeature::Bookings)
            <x-tenant.feature-locked :feature="PlanFeature::Bookings" />
        @endfeature
    @endif

    <section class="flex flex-col gap-4">
        <flux:heading size="lg" level="2">{{ __('Waiting for a decision') }}</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Booking') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Asked for') }}</flux:table.column>
                <flux:table.column class="max-md:hidden" align="end">{{ __('Paid on booking') }}</flux:table.column>
                <flux:table.column class="max-lg:hidden">{{ __('Reason') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->requested as $refund)
                    <flux:table.row :key="$refund->id">
                        <flux:table.cell>
                            <span class="font-medium">{{ $refund->booking?->reference }}</span>
                            <flux:text size="sm"><x-tenant.masked>{{ $refund->booking?->customer_name }}</x-tenant.masked> · {{ $refund->requested_at?->diffForHumans() }}</flux:text>
                        </flux:table.cell>

                        <flux:table.cell align="end"><x-tenant.masked>{{ $refund->amount->format() }}</x-tenant.masked></flux:table.cell>

                        <flux:table.cell class="max-md:hidden" align="end"><x-tenant.masked>{{ $refund->booking?->amount_paid->format() }}</x-tenant.masked></flux:table.cell>

                        <flux:table.cell class="max-lg:hidden whitespace-normal">{{ $refund->reason ?? '—' }}</flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-1">
                                @can('decide', $refund)
                                    <flux:button size="xs" variant="primary" inset="top bottom" wire:click="confirmApprove({{ $refund->id }})">{{ __('Approve') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" inset="top bottom" wire:click="confirmReject({{ $refund->id }})">{{ __('Reject') }}</flux:button>
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="py-12 text-center">{{ __('No refunds are waiting for a decision.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <section class="flex flex-col gap-4">
        <flux:heading size="lg" level="2">{{ __('Approved, to pay out') }}</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Booking') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column class="max-md:hidden">{{ __('Goes back') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->toPayOut as $refund)
                    <flux:table.row :key="$refund->id">
                        <flux:table.cell>
                            <span class="font-medium">{{ $refund->booking?->reference }}</span>
                            <flux:text size="sm"><x-tenant.masked>{{ $refund->booking?->customer_name }}</x-tenant.masked> · {{ $refund->type->label() }}</flux:text>
                        </flux:table.cell>

                        <flux:table.cell align="end"><x-tenant.masked>{{ $refund->amount->format() }}</x-tenant.masked></flux:table.cell>

                        <flux:table.cell class="max-md:hidden">
                            {{ $refund->goesThroughStripe() ? __('Through Stripe') : __('By hand') }}
                            @if ($refund->status === RefundStatus::Failed)
                                <flux:badge color="red" size="sm" inset="top bottom">{{ __('Stripe failed it') }}</flux:badge>
                            @elseif ($refund->stripe_refund_id)
                                <flux:badge color="amber" size="sm" inset="top bottom">{{ __('Stripe is working on it') }}</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            @can('processByHand', $refund)
                                <flux:button size="xs" variant="primary" inset="top bottom" wire:click="confirmProcess({{ $refund->id }})">{{ __('Pay out') }}</flux:button>
                            @endcan
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="py-12 text-center">{{ __('Every approved refund has been paid out.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    @if ($this->recentlyClosed->isNotEmpty())
        <section class="flex flex-col gap-4">
            <flux:heading size="lg" level="2">{{ __('Recently closed') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Booking') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                    <flux:table.column>{{ __('Outcome') }}</flux:table.column>
                    <flux:table.column class="max-md:hidden">{{ __('On') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->recentlyClosed as $refund)
                        <flux:table.row :key="$refund->id">
                            <flux:table.cell>{{ $refund->booking?->reference }}</flux:table.cell>
                            <flux:table.cell align="end"><x-tenant.masked>{{ $refund->amount->format() }}</x-tenant.masked></flux:table.cell>
                            <flux:table.cell class="whitespace-normal">
                                <flux:badge :color="$refund->status->color()" size="sm" inset="top bottom">{{ $refund->status->label() }}</flux:badge>
                                @if ($refund->status === RefundStatus::Rejected && $refund->decision_reason)
                                    <flux:text size="sm">{{ $refund->decision_reason }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden">{{ ($refund->processed_at ?? $refund->decided_at)?->toFormattedDateString() }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    @endif

    <flux:modal name="approve-refund" class="md:w-[28rem]">
        @if ($this->approving)
            <form wire:submit="approve" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Approve a refund on :reference?', ['reference' => $this->approving->booking?->reference]) }}</flux:heading>
                    <flux:text class="mt-2">
                        <x-tenant.masked>{{ __(':requested asked for; :refundable can still be refunded on the booking.', ['requested' => $this->approving->amount->format(), 'refundable' => $this->refundable?->format()]) }}</x-tenant.masked>
                    </flux:text>
                    <flux:text class="mt-2">{{ __('Approving everything still paid cancels the booking and frees its units.') }}</flux:text>
                </div>

                <flux:input wire:model="amount" :label="__('Amount to approve')" inputmode="decimal" required />

                <flux:error name="refund" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary">{{ __('Approve') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

    <flux:modal name="reject-refund" class="md:w-[28rem]">
        <form wire:submit="reject" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Reject this refund?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('The money and the booking stay as they are. The customer sees your reason, and it is kept in the audit log.') }}</flux:text>
            </div>

            <flux:textarea wire:model="decision_reason" :label="__('Reason')" rows="3" required />

            <flux:error name="refund" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="danger">{{ __('Reject') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="process-refund" class="md:w-[28rem]">
        @if ($this->processing)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Pay out the refund on :reference?', ['reference' => $this->processing->booking?->reference]) }}</flux:heading>
                    <flux:text class="mt-2">
                        @if ($this->processing->goesThroughStripe())
                            <x-tenant.masked>{{ __(':amount goes back to the card the customer paid with.', ['amount' => $this->processing->amount->format()]) }}</x-tenant.masked>
                        @else
                            <x-tenant.masked>{{ __('Hand :amount back to the customer, then record it here.', ['amount' => $this->processing->amount->format()]) }}</x-tenant.masked>
                        @endif
                    </flux:text>
                </div>

                @if ($this->processing->goesThroughStripe())
                    @cannot('processThroughStripe', $this->processing)
                        @unlessfeature(PlanFeature::StripeRefunds)
                            <x-tenant.feature-locked :feature="PlanFeature::StripeRefunds" />
                        @endfeature
                    @endcannot
                @endif

                <flux:error name="refund" />

                <div class="flex flex-wrap justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    @if ($this->processing->goesThroughStripe())
                        <flux:button variant="filled" wire:click="processByHand">{{ __('Paid back another way') }}</flux:button>

                        @can('processThroughStripe', $this->processing)
                            <flux:button variant="primary" wire:click="processThroughStripe">{{ __('Refund through Stripe') }}</flux:button>
                        @endcan
                    @else
                        <flux:button variant="primary" wire:click="processByHand">{{ __('Record as paid back') }}</flux:button>
                    @endif
                </div>
            </div>
        @endif
    </flux:modal>
</div>
