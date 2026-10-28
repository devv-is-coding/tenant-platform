<?php

use App\Actions\Restore\RequestRestoreAction;
use App\Actions\Restore\RestoreRecordAction;
use App\Enums\RestoreRequestStatus;
use App\Models\RestoreRequest;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
| The agency's side of restoring: only a super admin restores, so the Admin
| lists the agency's deleted staff and billing records, asks for one back
| with a reason, and follows each request to its outcome. This page is how
| the Admin learns the outcome until in-app notices exist (TA-22).
|
| Everything here is central: the deleted records and the requests are the
| platform's rows, always read through the agency's own relations or its id,
| never another agency's. Its Admin's alone, in any status and on any plan:
| an agency always reaches its own records.
|
| Every read and action re-checks the policy, since Livewire update requests
| do not re-run the route middleware's role checks on their own.
*/
new #[Title('Restore requests')] class extends Component {
    use WithPagination;

    /**
     * The deleted record the request form is open for. Set only by
     * confirmRequest(), never by the browser.
     */
    #[Locked]
    public ?string $requesting_type = null;

    #[Locked]
    public ?int $requesting_id = null;

    public string $reason = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAgencyRestoreRequests', $this->currentAgency());
    }

    /**
     * Get the agency the page is for.
     */
    #[Computed]
    public function agency(): Tenant
    {
        $agency = $this->currentAgency();
        $this->authorize('viewAgencyRestoreRequests', $agency);

        return $agency;
    }

    /**
     * Get the agency's deleted records, most recently deleted first, each
     * marked when a restore of it is already pending.
     *
     * @return list<array{type: string, id: int, kind: string, name: string, deleted_at: CarbonInterface|null, pending: bool}>
     */
    #[Computed]
    public function deletedRecords(): array
    {
        $morphTypes = [
            'user' => (new User)->getMorphClass(),
            'subscription' => (new Subscription)->getMorphClass(),
            'invoice' => (new SubscriptionInvoice)->getMorphClass(),
            'payment' => (new SubscriptionPayment)->getMorphClass(),
        ];

        $pending = RestoreRequest::query()
            ->where('tenant_id', $this->agency->id)
            ->where('status', RestoreRequestStatus::Pending)
            ->get(['restorable_type', 'restorable_id'])
            ->map(fn (RestoreRequest $request): string => "{$request->restorable_type}:{$request->restorable_id}")
            ->all();

        return array_map(fn (array $record): array => [
            ...$record,
            'pending' => in_array("{$morphTypes[$record['type']]}:{$record['id']}", $pending, true),
        ], app(RestoreRecordAction::class)->deletedRecordsOf($this->agency));
    }

    /**
     * Get one page of the agency's restore requests, newest first, each with
     * the record it asks for named, or marked as gone.
     *
     * @return LengthAwarePaginator<int, array{id: int, name: string, kind: string|null, reason: string, requested_on: CarbonInterface|null, requester: string|null, status: RestoreRequestStatus, decided_on: CarbonInterface|null, decision_reason: string|null}>
     */
    #[Computed]
    public function requests(): LengthAwarePaginator
    {
        $restoreRecord = app(RestoreRecordAction::class);

        return RestoreRequest::query()
            ->where('tenant_id', $this->agency->id)
            ->with(['restorable', 'requester'])
            ->latest('id')
            ->paginate(10)
            ->through(fn (RestoreRequest $request): array => [
                'id' => $request->id,
                'name' => $request->restorable === null ? __('A record that no longer exists') : $restoreRecord->labelFor($request->restorable),
                'kind' => $request->restorable === null ? null : $restoreRecord->kindFor($request->restorable),
                'reason' => $request->reason,
                'requested_on' => $request->created_at,
                'requester' => $request->requester?->full_name,
                'status' => $request->status,
                'decided_on' => $request->decided_at,
                'decision_reason' => $request->decision_reason,
            ]);
    }

    /**
     * Describe the record the request form is open for.
     */
    #[Computed]
    public function requesting(): ?string
    {
        if ($this->requesting_type === null || $this->requesting_id === null) {
            return null;
        }

        return app(RestoreRecordAction::class)->labelFor($this->deletedRecord($this->requesting_type, $this->requesting_id));
    }

    /**
     * Open the request form for one of the agency's deleted records.
     */
    public function confirmRequest(string $type, int $id): void
    {
        $record = $this->deletedRecord($type, $id);

        $this->resetErrorBag();
        $this->reset('reason');
        $this->requesting_type = $type;
        $this->requesting_id = $record->id;
        unset($this->requesting);

        Flux::modal('request-restore')->show();
    }

    /**
     * Ask the platform to restore the record the form is open for.
     * RequestRestoreAction authorizes itself as well: the agency's own admin,
     * for the agency's own deleted record, once while a request is pending.
     */
    public function sendRequest(RequestRestoreAction $requestRestore): void
    {
        $record = $this->deletedRecord((string) $this->requesting_type, (int) $this->requesting_id);

        $requestRestore->handle(Auth::user(), $this->agency, $record, $this->only('reason'));

        $name = app(RestoreRecordAction::class)->labelFor($record);
        $this->reset('requesting_type', 'requesting_id', 'reason');
        unset($this->deletedRecords, $this->requests, $this->requesting);
        $this->resetPage();
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __('Restore of :name requested. The platform will restore it or tell you why not.', ['name' => $name]));
    }

    /**
     * Find one of the agency's deleted records, or fail with a 404.
     */
    private function deletedRecord(string $type, int $id): User|Subscription|SubscriptionInvoice|SubscriptionPayment
    {
        return app(RestoreRecordAction::class)->findDeleted($this->agency, $type, $id);
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
        <flux:heading size="xl" level="1">{{ __('Restore requests') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Only the platform can bring back a deleted record. Ask for one here, with the reason, and follow what was decided.') }}</flux:text>
    </div>

    <section class="flex flex-col gap-4">
        <div>
            <flux:heading size="lg" level="2">{{ __('Deleted records') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Restoring a record brings back what was deleted with it. A record whose parent is still deleted needs the parent restored first.') }}</flux:text>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Record') }}</flux:table.column>
                <flux:table.column class="max-sm:hidden">{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Deleted') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->deletedRecords as $deleted)
                    <flux:table.row :key="$deleted['type'].'-'.$deleted['id']">
                        <flux:table.cell variant="strong">
                            {{ $deleted['name'] }}
                            <flux:text size="sm" class="sm:hidden">{{ $deleted['kind'] }}</flux:text>
                        </flux:table.cell>

                        <flux:table.cell class="max-sm:hidden">{{ $deleted['kind'] }}</flux:table.cell>

                        <flux:table.cell>{{ $deleted['deleted_at']?->toFormattedDateString() }}</flux:table.cell>

                        <flux:table.cell align="end">
                            @if ($deleted['pending'])
                                <flux:badge :color="RestoreRequestStatus::Pending->color()" size="sm" inset="top bottom">{{ __('Requested') }}</flux:badge>
                            @else
                                <flux:button size="xs" variant="ghost" icon="arrow-uturn-left" inset="top bottom" wire:click="confirmRequest('{{ $deleted['type'] }}', {{ $deleted['id'] }})">
                                    {{ __('Ask to restore') }}
                                </flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="py-8 text-center">{{ __('Nothing of the agency\'s has been deleted.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <section class="flex flex-col gap-4">
        <flux:heading size="lg" level="2">{{ __('Requests') }}</flux:heading>

        <flux:table :paginate="$this->requests">
            <flux:table.columns>
                <flux:table.column>{{ __('Record') }}</flux:table.column>
                <flux:table.column class="max-md:hidden">{{ __('Asked') }}</flux:table.column>
                <flux:table.column>{{ __('Outcome') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->requests as $request)
                    <flux:table.row :key="$request['id']">
                        <flux:table.cell class="align-top">
                            <span class="font-medium text-zinc-800 dark:text-white">{{ $request['name'] }}</span>
                            @if ($request['kind'])
                                <flux:text size="sm">{{ $request['kind'] }}</flux:text>
                            @endif
                            <flux:text size="sm" class="mt-1 max-w-md whitespace-normal">{{ $request['reason'] }}</flux:text>
                        </flux:table.cell>

                        <flux:table.cell class="align-top max-md:hidden">
                            {{ $request['requested_on']?->toFormattedDateString() }}
                            <flux:text size="sm">{{ $request['requester'] ?? '—' }}</flux:text>
                        </flux:table.cell>

                        <flux:table.cell class="align-top">
                            <flux:badge :color="$request['status']->color()" size="sm" inset="top bottom">{{ $request['status']->label() }}</flux:badge>

                            @if ($request['decided_on'])
                                <flux:text size="sm" class="mt-1">{{ $request['decided_on']->toFormattedDateString() }}</flux:text>
                            @endif

                            @if ($request['status'] === RestoreRequestStatus::Declined && $request['decision_reason'])
                                <flux:text size="sm" class="mt-1 max-w-sm whitespace-normal" data-test="decline-reason">{{ __('Reason: :reason', ['reason' => $request['decision_reason']]) }}</flux:text>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="3" class="py-8 text-center">{{ __('The agency has not asked for anything to be restored.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <flux:modal name="request-restore" class="md:w-[28rem]">
        @if ($this->requesting)
            <form wire:submit="sendRequest" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Ask to restore :name?', ['name' => $this->requesting]) }}</flux:heading>
                    <flux:text class="mt-2">{{ __('The platform restores it or declines with a reason. You see the outcome here.') }}</flux:text>
                </div>

                <flux:textarea wire:model="reason" :label="__('Why should it come back?')" rows="3" required />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary">{{ __('Send request') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
