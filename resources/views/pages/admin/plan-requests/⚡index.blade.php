<?php

use App\Actions\Subscriptions\DecidePlanChangeRequestAction;
use App\Enums\PlanChangeRequestStatus;
use App\Models\PlanChangeRequest;
use App\ValueObjects\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Agency admins asking to be put on a plan. An agency cannot pick its own
| plan: each request is approved here, which puts the agency on the plan, or
| declined with a reason the admin sees on their billing page.
*/
new #[Title('Plan requests')] class extends Component {
    /**
     * The number of closed requests listed, most recently closed first.
     */
    private const RECENTLY_DECIDED_LIMIT = 10;

    /**
     * The request the approve or decline confirmation is open for. Set only
     * by confirmApprove() and confirmDecline(), never by the browser.
     */
    #[Locked]
    public ?int $approving_id = null;

    #[Locked]
    public ?int $declining_id = null;

    public string $decision_reason = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', PlanChangeRequest::class);
    }

    /**
     * Open the approve confirmation for a pending request.
     */
    public function confirmApprove(int $id): void
    {
        $request = PlanChangeRequest::query()->findOrFail($id);
        $this->authorize('decide', $request);

        $this->resetErrorBag();
        $this->approving_id = $request->id;
        unset($this->approving);

        Flux::modal('approve-request')->show();
    }

    /**
     * Approve the request the confirmation is open for, putting the agency
     * on the plan.
     *
     * Authorized here, and again by the action, because Livewire's update
     * requests do not re-run the console's route middleware.
     */
    public function approve(DecidePlanChangeRequestAction $decide): void
    {
        $request = PlanChangeRequest::query()->with(['tenant', 'plan'])->findOrFail($this->approving_id);
        $this->authorize('decide', $request);

        $subscription = $decide->approve(Auth::user(), $request);

        $this->reset('approving_id');
        unset($this->pending, $this->recentlyDecided, $this->approving);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __(':agency is now on :plan.', [
            'agency' => $request->tenant?->name ?? $request->tenant_id,
            'plan' => $subscription->plan_name,
        ]));
    }

    /**
     * Open the decline form for a pending request.
     */
    public function confirmDecline(int $id): void
    {
        $request = PlanChangeRequest::query()->findOrFail($id);
        $this->authorize('decide', $request);

        $this->resetErrorBag();
        $this->reset('decision_reason');
        $this->declining_id = $request->id;

        Flux::modal('decline-request')->show();
    }

    /**
     * Decline the request the form is open for, with a reason.
     */
    public function decline(DecidePlanChangeRequestAction $decide): void
    {
        $request = PlanChangeRequest::query()->findOrFail($this->declining_id);
        $this->authorize('decide', $request);

        $decide->decline(Auth::user(), $request, $this->only('decision_reason'));

        $this->reset('declining_id', 'decision_reason');
        unset($this->pending, $this->recentlyDecided);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __('The request was declined.'));
    }

    /**
     * Get the request the approve confirmation is open for.
     */
    #[Computed]
    public function approving(): ?PlanChangeRequest
    {
        return $this->approving_id === null ? null : PlanChangeRequest::query()->with(['tenant.liveSubscription', 'plan'])->find($this->approving_id);
    }

    /**
     * Get the requests waiting for a decision, oldest first.
     *
     * Authorized again here because Livewire's update requests do not re-run
     * the console's route middleware.
     *
     * @return Collection<int, PlanChangeRequest>
     */
    #[Computed]
    public function pending(): Collection
    {
        $this->authorize('viewAny', PlanChangeRequest::class);

        return PlanChangeRequest::query()
            ->with(['tenant.liveSubscription', 'plan', 'requester'])
            ->where('status', PlanChangeRequestStatus::Pending)
            ->oldest('id')
            ->get();
    }

    /**
     * Get the most recently closed requests.
     *
     * @return Collection<int, PlanChangeRequest>
     */
    #[Computed]
    public function recentlyDecided(): Collection
    {
        $this->authorize('viewAny', PlanChangeRequest::class);

        return PlanChangeRequest::query()
            ->with(['tenant', 'plan'])
            ->where('status', '!=', PlanChangeRequestStatus::Pending)
            ->latest('decided_at')
            ->latest('id')
            ->limit(self::RECENTLY_DECIDED_LIMIT)
            ->get();
    }
}; ?>

<div class="flex flex-col gap-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Plan requests') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Agency admins asking to be put on a plan. Approving starts the plan today and ends the one the agency was on.') }}</flux:text>
    </div>

    <section class="flex flex-col gap-4">
        <flux:heading size="lg" level="2">{{ __('Waiting for a decision') }}</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Agency') }}</flux:table.column>
                <flux:table.column>{{ __('Asked for') }}</flux:table.column>
                <flux:table.column class="max-md:hidden">{{ __('Now on') }}</flux:table.column>
                <flux:table.column class="max-lg:hidden">{{ __('Note') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->pending as $request)
                    <flux:table.row :key="$request->id">
                        <flux:table.cell>
                            <flux:link :href="route('admin.tenants.show', $request->tenant_id)" variant="ghost" class="font-medium" wire:navigate>
                                {{ $request->tenant?->name ?? $request->tenant_id }}
                            </flux:link>
                            <flux:text size="sm">{{ $request->requester?->full_name ?? '—' }} · {{ $request->created_at?->diffForHumans() }}</flux:text>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($request->plan)
                                <span class="flex flex-wrap items-center gap-2">
                                    {{ $request->plan->name }}
                                    <flux:badge size="sm" :color="$request->plan->tier->color()" inset="top bottom">{{ $request->plan->tier->label() }}</flux:badge>
                                    @if ($request->plan->trashed())
                                        <flux:badge size="sm" color="red" inset="top bottom">{{ __('Withdrawn') }}</flux:badge>
                                    @endif
                                </span>
                                <flux:text size="sm">{{ Money::ofMinor($request->plan->amount_minor, $request->plan->currency)->format() }} · {{ $request->plan->interval->label() }}</flux:text>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="max-md:hidden">{{ $request->tenant?->liveSubscription?->plan_name ?? __('No plan') }}</flux:table.cell>

                        <flux:table.cell class="max-lg:hidden whitespace-normal">{{ $request->reason ?? '—' }}</flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-1">
                                @can('decide', $request)
                                    <flux:button size="xs" variant="primary" inset="top bottom" wire:click="confirmApprove({{ $request->id }})">{{ __('Approve') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" inset="top bottom" wire:click="confirmDecline({{ $request->id }})">{{ __('Decline') }}</flux:button>
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="py-12 text-center">{{ __('No plan requests are waiting.') }}</flux:table.cell>
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
                    <flux:table.column>{{ __('Agency') }}</flux:table.column>
                    <flux:table.column>{{ __('Asked for') }}</flux:table.column>
                    <flux:table.column>{{ __('Decision') }}</flux:table.column>
                    <flux:table.column class="max-md:hidden">{{ __('Decided') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->recentlyDecided as $request)
                        <flux:table.row :key="$request->id">
                            <flux:table.cell>{{ $request->tenant?->name ?? $request->tenant_id }}</flux:table.cell>
                            <flux:table.cell>{{ $request->plan?->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$request->status->color()" size="sm" inset="top bottom">{{ $request->status->label() }}</flux:badge>
                                @if ($request->decision_reason)
                                    <flux:text size="sm">{{ $request->decision_reason }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden">{{ $request->decided_at?->toFormattedDateString() }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    @endif

    <flux:modal name="approve-request" class="md:w-[28rem]">
        @if ($this->approving)
            <form wire:submit="approve" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Put :agency on :plan?', ['agency' => $this->approving->tenant?->name ?? $this->approving->tenant_id, 'plan' => $this->approving->plan?->name]) }}</flux:heading>
                    <flux:text class="mt-2">
                        @if ($this->approving->tenant?->liveSubscription)
                            {{ __('The plan starts today and :current ends today.', ['current' => $this->approving->tenant->liveSubscription->plan_name]) }}
                        @else
                            {{ __('The plan starts today.') }}
                        @endif
                    </flux:text>
                </div>

                <flux:error name="decision" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary">{{ __('Approve') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

    <flux:modal name="decline-request" class="md:w-[28rem]">
        <form wire:submit="decline" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Decline this request?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('The agency stays on its plan. The agency\'s admin sees the reason on their billing page, and it is kept in the audit log.') }}</flux:text>
            </div>

            <flux:textarea wire:model="decision_reason" :label="__('Reason')" rows="3" required />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="danger">{{ __('Decline') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
