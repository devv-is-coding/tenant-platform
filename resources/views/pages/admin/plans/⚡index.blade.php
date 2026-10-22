<?php

use App\Actions\Subscriptions\DeleteSubscriptionPlanAction;
use App\Actions\Subscriptions\SaveSubscriptionPlanAction;
use App\Enums\BillingInterval;
use App\Models\SubscriptionPlan;
use App\ValueObjects\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| The platform's price list. A super admin sets what each plan costs here;
| putting an agency on a plan copies its terms onto the subscription, so
| repricing a plan never changes what an agency already agreed to.
*/
new #[Title('Plans')] class extends Component {
    /**
     * The plan the form is editing, or null while it adds a new one. Set only
     * by editPlan(), never by the browser.
     */
    #[Locked]
    public ?int $editing_id = null;

    public string $name = '';

    public string $amount = '';

    public int $interval = BillingInterval::Monthly->value;

    /**
     * The plan the delete confirmation is open for. Set only by
     * confirmDelete(), never by the browser.
     */
    #[Locked]
    public ?int $deleting_id = null;

    public string $delete_reason = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', SubscriptionPlan::class);
    }

    /**
     * Open an empty form for a new plan.
     */
    public function createPlan(): void
    {
        $this->authorize('create', SubscriptionPlan::class);

        $this->resetErrorBag();
        $this->reset('editing_id', 'name', 'amount', 'interval');

        Flux::modal('plan-form')->show();
    }

    /**
     * Open the form filled with an existing plan's terms.
     */
    public function editPlan(int $id): void
    {
        $plan = SubscriptionPlan::query()->findOrFail($id);
        $this->authorize('update', $plan);

        $this->resetErrorBag();
        $this->editing_id = $plan->id;
        $this->name = $plan->name;
        $this->amount = Money::ofMinor($plan->amount_minor, $plan->currency)->toDecimal();
        $this->interval = $plan->interval->value;

        Flux::modal('plan-form')->show();
    }

    /**
     * Add the plan, or save the changes to the one being edited.
     *
     * Authorized here because Livewire's update requests do not re-run the
     * console's route middleware.
     */
    public function savePlan(SaveSubscriptionPlanAction $savePlan): void
    {
        $plan = $this->editing_id === null ? null : SubscriptionPlan::query()->findOrFail($this->editing_id);
        $plan === null ? $this->authorize('create', SubscriptionPlan::class) : $this->authorize('update', $plan);

        $saved = $savePlan->handle(Auth::user(), $this->only(['name', 'amount', 'interval']), $plan);

        $this->reset('editing_id', 'name', 'amount', 'interval');
        unset($this->plans);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: $plan === null
            ? __(':name was added to the price list.', ['name' => $saved->name])
            : __(':name was saved.', ['name' => $saved->name]));
    }

    /**
     * Open the delete confirmation for a plan.
     */
    public function confirmDelete(int $id): void
    {
        $plan = SubscriptionPlan::query()->findOrFail($id);
        $this->authorize('delete', $plan);

        $this->resetErrorBag();
        $this->reset('delete_reason');
        $this->deleting_id = $plan->id;
        unset($this->deletingPlan);

        Flux::modal('delete-plan')->show();
    }

    /**
     * Withdraw the plan the confirmation is open for from the price list.
     */
    public function deletePlan(DeleteSubscriptionPlanAction $deletePlan): void
    {
        $plan = SubscriptionPlan::query()->findOrFail($this->deleting_id);
        $this->authorize('delete', $plan);

        $deletePlan->handle(Auth::user(), $plan, $this->only('delete_reason'));

        $this->reset('deleting_id', 'delete_reason');
        unset($this->plans, $this->deletingPlan);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __(':name was deleted.', ['name' => $plan->name]));
    }

    /**
     * Get the price list, with how many agencies are live on each plan.
     *
     * Authorized again here because Livewire's update requests do not re-run
     * the console's route middleware.
     *
     * @return Collection<int, SubscriptionPlan>
     */
    #[Computed]
    public function plans(): Collection
    {
        $this->authorize('viewAny', SubscriptionPlan::class);

        return SubscriptionPlan::query()
            ->withCount(['subscriptions as live_subscriptions_count' => fn (Builder $query) => $query->whereNotNull('active_for_tenant_id')])
            ->orderBy('name')
            ->get();
    }

    /**
     * Get the plan the delete confirmation is open for.
     */
    #[Computed]
    public function deletingPlan(): ?SubscriptionPlan
    {
        return $this->deleting_id === null ? null : SubscriptionPlan::query()->find($this->deleting_id);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Plans') }}</flux:heading>
            <flux:text class="mt-1">{{ __('The price list agencies are put on. Changing a price applies to agencies put on the plan from now on; those already on it keep what they agreed to.') }}</flux:text>
        </div>

        @can('create', SubscriptionPlan::class)
            <flux:button variant="primary" icon="plus" wire:click="createPlan">{{ __('New plan') }}</flux:button>
        @endcan
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Plan') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Price') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden">{{ __('Billed') }}</flux:table.column>
            <flux:table.column align="end" class="max-md:hidden">{{ __('Agencies on it') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->plans as $plan)
                <flux:table.row :key="$plan->id">
                    <flux:table.cell variant="strong">
                        {{ $plan->name }}
                        <flux:text size="sm" class="sm:hidden">{{ $plan->interval->label() }}</flux:text>
                    </flux:table.cell>

                    <flux:table.cell align="end">{{ Money::ofMinor($plan->amount_minor, $plan->currency)->format() }}</flux:table.cell>

                    <flux:table.cell class="max-sm:hidden">{{ $plan->interval->label() }}</flux:table.cell>

                    <flux:table.cell align="end" class="max-md:hidden tabular-nums">{{ $plan->live_subscriptions_count }}</flux:table.cell>

                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            @can('update', $plan)
                                <flux:button size="xs" variant="ghost" icon="pencil-square" inset="top bottom" wire:click="editPlan({{ $plan->id }})" :aria-label="__('Edit :name', ['name' => $plan->name])" />
                            @endcan

                            @can('delete', $plan)
                                <flux:button size="xs" variant="ghost" icon="trash" inset="top bottom" wire:click="confirmDelete({{ $plan->id }})" :aria-label="__('Delete :name', ['name' => $plan->name])" />
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-12 text-center">{{ __('No plans yet. Add one to start putting agencies on it.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="plan-form" class="md:w-[28rem]">
        <form wire:submit="savePlan" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editing_id === null ? __('New plan') : __('Edit :name', ['name' => $name]) }}</flux:heading>
                <flux:text class="mt-2">
                    @if ($editing_id === null)
                        {{ __('Agencies put on this plan are billed this amount every period.') }}
                    @else
                        {{ __('The new terms apply to agencies put on the plan from now on.') }}
                    @endif
                </flux:text>
            </div>

            <flux:input wire:model="name" :label="__('Plan name')" required />

            <flux:field>
                <flux:label>{{ __('Amount per billing period') }}</flux:label>

                <flux:input.group>
                    <flux:input.group.prefix>{{ config('tenancy.billing_currency') }}</flux:input.group.prefix>

                    <flux:input wire:model="amount" inputmode="decimal" required />
                </flux:input.group>

                <flux:error name="amount" />
            </flux:field>

            <flux:select wire:model="interval" :label="__('Billed')">
                @foreach (BillingInterval::cases() as $billingInterval)
                    <flux:select.option :value="$billingInterval->value">{{ $billingInterval->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ $editing_id === null ? __('Add plan') : __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-plan" class="md:w-[28rem]">
        @if ($this->deletingPlan)
            <form wire:submit="deletePlan" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete :name?', ['name' => $this->deletingPlan->name]) }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('It can no longer be chosen for an agency. Agencies already on it keep their subscription and price.') }}
                        {{ __('Nothing is destroyed: the row is kept with a deletion date.') }}
                    </flux:text>
                </div>

                <flux:textarea wire:model="delete_reason" :label="__('Reason')" rows="3" required />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="danger">{{ __('Delete') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
