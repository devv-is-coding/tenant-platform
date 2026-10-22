<?php

use App\Actions\Restore\DeclineRestoreRequestAction;
use App\Actions\Restore\RestoreRecordAction;
use App\Enums\RestoreRequestStatus;
use App\Models\RestoreRequest;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Agency admins' requests to bring back records their staff deleted. Only a
| super admin restores; each request is restored or declined from here.
*/
new #[Title('Restore requests')] class extends Component {
    /**
     * The number of decided requests listed, most recently decided first.
     */
    private const RECENTLY_DECIDED_LIMIT = 10;

    /**
     * The request the decline form is open for. Set only by confirmDecline(),
     * never by the browser.
     */
    #[Locked]
    public ?int $declining_id = null;

    public string $decision_reason = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', RestoreRequest::class);
    }

    /**
     * Restore the record a pending request asks for, closing the request.
     *
     * Authorized here because Livewire's update requests do not re-run the
     * console's route middleware.
     */
    public function restore(int $id, RestoreRecordAction $restoreRecord): void
    {
        $request = RestoreRequest::query()->with(['tenant', 'restorable'])->findOrFail($id);
        $this->authorize('decide', $request);

        $record = $request->restorable
            ?? throw ValidationException::withMessages(['restore' => __('The record no longer exists. Decline the request instead.')]);
        $tenant = $request->tenant ?? abort(404);

        $restoreRecord->handle(Auth::user(), $tenant, $record, $request);

        unset($this->pending, $this->recentlyDecided);
        Flux::toast(variant: 'success', text: __(':name was restored.', ['name' => $restoreRecord->labelFor($record)]));
    }

    /**
     * Open the decline form for a pending request.
     */
    public function confirmDecline(int $id): void
    {
        $request = RestoreRequest::query()->findOrFail($id);
        $this->authorize('decide', $request);

        $this->resetErrorBag();
        $this->reset('decision_reason');
        $this->declining_id = $request->id;

        Flux::modal('decline-request')->show();
    }

    /**
     * Decline the request the form is open for, with a reason.
     */
    public function decline(DeclineRestoreRequestAction $declineRequest): void
    {
        $request = RestoreRequest::query()->findOrFail($this->declining_id);
        $this->authorize('decide', $request);

        $declineRequest->handle(Auth::user(), $request, $this->only('decision_reason'));

        $this->reset('declining_id', 'decision_reason');
        unset($this->pending, $this->recentlyDecided);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __('The request was declined.'));
    }

    /**
     * Get the requests waiting for a decision, oldest first.
     *
     * Authorized again here because Livewire's update requests do not re-run
     * the console's route middleware.
     *
     * @return Collection<int, RestoreRequest>
     */
    #[Computed]
    public function pending(): Collection
    {
        $this->authorize('viewAny', RestoreRequest::class);

        return RestoreRequest::query()
            ->with(['tenant', 'requester', 'restorable'])
            ->where('status', RestoreRequestStatus::Pending)
            ->oldest('id')
            ->get();
    }

    /**
     * Get the most recently decided requests.
     *
     * @return Collection<int, RestoreRequest>
     */
    #[Computed]
    public function recentlyDecided(): Collection
    {
        return RestoreRequest::query()
            ->with(['tenant', 'restorable'])
            ->where('status', '!=', RestoreRequestStatus::Pending)
            ->latest('decided_at')
            ->latest('id')
            ->limit(self::RECENTLY_DECIDED_LIMIT)
            ->get();
    }

    /**
     * Describe a request's record the way the console names it.
     */
    public function recordLabel(RestoreRequest $request): string
    {
        $record = $request->restorable;

        $kind = match (true) {
            $record instanceof User => __('User'),
            $record instanceof Subscription => __('Subscription'),
            $record instanceof SubscriptionInvoice => __('Invoice'),
            $record instanceof SubscriptionPayment => __('Payment'),
            default => __('Record'),
        };

        $name = $record === null ? "#{$request->restorable_id}" : app(RestoreRecordAction::class)->labelFor($record);

        return "{$kind} · {$name}";
    }
}; ?>

<div class="flex flex-col gap-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Restore requests') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Agency admins asking for records their staff deleted. Restoring brings back what was deleted with the record.') }}</flux:text>
    </div>

    <flux:error name="restore" />

    <section class="flex flex-col gap-4">
        <flux:heading size="lg" level="2">{{ __('Waiting for a decision') }}</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Agency') }}</flux:table.column>
                <flux:table.column>{{ __('Record') }}</flux:table.column>
                <flux:table.column class="max-md:hidden">{{ __('Requested by') }}</flux:table.column>
                <flux:table.column class="max-lg:hidden">{{ __('Reason') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->pending as $request)
                    <flux:table.row :key="$request->id">
                        <flux:table.cell>
                            <flux:link :href="route('admin.tenants.show', $request->tenant_id)" variant="ghost" class="font-medium" wire:navigate>
                                {{ $request->tenant?->name ?? $request->tenant_id }}
                            </flux:link>
                            <flux:text size="sm">{{ $request->created_at?->diffForHumans() }}</flux:text>
                        </flux:table.cell>

                        <flux:table.cell>
                            {{ $this->recordLabel($request) }}
                            <flux:text size="sm" class="lg:hidden">{{ $request->reason }}</flux:text>
                        </flux:table.cell>

                        <flux:table.cell class="max-md:hidden">{{ $request->requester?->full_name ?? '—' }}</flux:table.cell>

                        <flux:table.cell class="max-lg:hidden whitespace-normal">{{ $request->reason }}</flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-1">
                                @can('decide', $request)
                                    <flux:button size="xs" variant="primary" inset="top bottom" wire:click="restore({{ $request->id }})">{{ __('Restore') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" inset="top bottom" wire:click="confirmDecline({{ $request->id }})">{{ __('Decline') }}</flux:button>
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="py-12 text-center">{{ __('No restore requests are waiting.') }}</flux:table.cell>
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
                    <flux:table.column>{{ __('Record') }}</flux:table.column>
                    <flux:table.column>{{ __('Decision') }}</flux:table.column>
                    <flux:table.column class="max-md:hidden">{{ __('Decided') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->recentlyDecided as $request)
                        <flux:table.row :key="$request->id">
                            <flux:table.cell>{{ $request->tenant?->name ?? $request->tenant_id }}</flux:table.cell>
                            <flux:table.cell>{{ $this->recordLabel($request) }}</flux:table.cell>
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

    <flux:modal name="decline-request" class="md:w-[28rem]">
        <form wire:submit="decline" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Decline this request?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('The record stays deleted. The reason is kept with the request and in the audit log.') }}</flux:text>
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
