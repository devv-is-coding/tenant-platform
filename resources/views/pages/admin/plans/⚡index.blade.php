<?php

use App\Actions\Subscriptions\DeleteSubscriptionPlanAction;
use App\Actions\Subscriptions\MovePlanFeatureAction;
use App\Actions\Subscriptions\SaveSubscriptionPlanAction;
use App\Enums\BillingInterval;
use App\Enums\PlanFeature;
use App\Enums\PlanTier;
use App\Models\PlanFeatureSetting;
use App\Models\SubscriptionPlan;
use App\Services\Plans\PlanFeatureMatrix;
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
| The platform's plans: what each tier costs, and which features each tier
| includes. A super admin sets prices here; putting an agency on a plan copies
| its terms, tier included, onto the subscription, so repricing a plan never
| changes what an agency already agreed to.
|
| Features are the exception, by design: which tier a feature starts at is
| read live, so moving one reaches every agency at once. A move up therefore
| says how many agencies lose the feature and needs a reason.
*/
new #[Title('Plans')] class extends Component {
    /**
     * The plan the form is editing, or null while it adds a new one. Set only
     * by editPlan(), never by the browser.
     */
    #[Locked]
    public ?int $editing_id = null;

    public string $name = '';

    public int $tier = PlanTier::Basic->value;

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
     * The feature the move form is open for. Set only by moveFeature(),
     * never by the browser.
     */
    #[Locked]
    public ?string $moving_feature = null;

    public int $minimum_tier = PlanTier::Basic->value;

    public string $move_reason = '';

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
        $this->reset('editing_id', 'name', 'tier', 'amount', 'interval');

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
        $this->tier = $plan->tier->value;
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

        $saved = $savePlan->handle(Auth::user(), $this->only(['name', 'tier', 'amount', 'interval']), $plan);

        $this->reset('editing_id', 'name', 'tier', 'amount', 'interval');
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
     * Open the move form for a feature, set to the tier it starts at now.
     */
    public function moveFeature(string $feature): void
    {
        $this->authorize('update', PlanFeatureSetting::class);

        $planFeature = PlanFeature::from($feature);

        $this->resetErrorBag();
        $this->reset('move_reason');
        $this->moving_feature = $planFeature->value;
        $this->minimum_tier = app(PlanFeatureMatrix::class)->minimumTierFor($planFeature)->value;

        Flux::modal('move-feature')->show();
    }

    /**
     * Move the feature the form is open for to the chosen tier.
     */
    public function saveFeatureMove(MovePlanFeatureAction $moveFeature): void
    {
        $this->authorize('update', PlanFeatureSetting::class);

        $feature = PlanFeature::from((string) $this->moving_feature);

        $moveFeature->handle(Auth::user(), $feature, $this->only(['minimum_tier', 'move_reason']));

        $this->resetErrorBag();
        $this->reset('moving_feature', 'minimum_tier', 'move_reason');
        unset($this->features);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __(':feature now starts at :tier.', [
            'feature' => $feature->label(),
            'tier' => app(PlanFeatureMatrix::class)->minimumTierFor($feature)->label(),
        ]));
    }

    /**
     * Get the price list, by tier then name, with how many agencies are live
     * on each plan.
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
            ->orderBy('tier')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get the tier every feature starts at, in the enum's order.
     *
     * @return array<string, PlanTier>
     */
    #[Computed]
    public function features(): array
    {
        $this->authorize('viewAny', PlanFeatureSetting::class);

        return app(PlanFeatureMatrix::class)->minimumTiers();
    }

    /**
     * Count the agencies that lose the feature being moved if it starts at
     * the tier now chosen in the form.
     */
    #[Computed]
    public function agenciesLosing(): int
    {
        $tier = PlanTier::tryFrom($this->minimum_tier);

        return $this->moving_feature === null || $tier === null
            ? 0
            : app(MovePlanFeatureAction::class)->agenciesLosing(PlanFeature::from($this->moving_feature), $tier);
    }

    /**
     * Determine if the tier chosen in the move form is above the feature's
     * current one, which takes it from agencies and needs a reason.
     */
    #[Computed]
    public function movingUp(): bool
    {
        return $this->moving_feature !== null
            && $this->minimum_tier > app(PlanFeatureMatrix::class)->minimumTierFor(PlanFeature::from($this->moving_feature))->value;
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

<div class="flex flex-col gap-10">
    <div>
        <flux:heading size="xl" level="1">{{ __('Plans') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Agencies pay for a tier of features, never for where they are. Tiers are cumulative: Pro includes everything in Advanced, and Advanced everything in Basic.') }}</flux:text>
    </div>

    <section class="flex flex-col gap-6" aria-labelledby="prices-heading">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="lg" level="2" id="prices-heading">{{ __('Prices') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Changing a price applies to agencies put on the plan from now on; those already on it keep what they agreed to.') }}</flux:text>
            </div>

            @can('create', \App\Models\SubscriptionPlan::class)
                <flux:button variant="primary" icon="plus" wire:click="createPlan">{{ __('New plan') }}</flux:button>
            @endcan
        </div>

        @if ($this->plans->isEmpty())
            <flux:card class="py-12 text-center">
                <flux:text>{{ __('No plans yet. Add one to start putting agencies on it.') }}</flux:text>
            </flux:card>
        @else
            @php($plansByTier = $this->plans->groupBy(fn (SubscriptionPlan $plan): int => $plan->tier->value))

            @foreach (PlanTier::cases() as $planTier)
                <div class="flex flex-col gap-2" wire:key="tier-{{ $planTier->value }}">
                    <div class="flex items-center gap-2">
                        <flux:badge :color="$planTier->color()" size="sm">{{ $planTier->label() }}</flux:badge>
                        <flux:text size="sm">{{ $planTier->pitch() }}</flux:text>
                    </div>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Plan') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Price') }}</flux:table.column>
                            <flux:table.column class="max-sm:hidden">{{ __('Billed') }}</flux:table.column>
                            <flux:table.column align="end" class="max-md:hidden">{{ __('Agencies on it') }}</flux:table.column>
                            <flux:table.column align="end"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($plansByTier->get($planTier->value, []) as $plan)
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
                                    <flux:table.cell colspan="5" class="py-6 text-center">{{ __('No :tier plan yet.', ['tier' => $planTier->label()]) }}</flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endforeach
        @endif
    </section>

    <section class="flex flex-col gap-6" aria-labelledby="features-heading">
        <div>
            <flux:heading size="lg" level="2" id="features-heading">{{ __('Features') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Which tier each feature starts at. A move applies to every agency at once, and nothing an agency made with a feature is deleted when it loses it.') }}</flux:text>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Feature') }}</flux:table.column>
                @foreach (PlanTier::cases() as $planTier)
                    <flux:table.column align="center">{{ $planTier->label() }}</flux:table.column>
                @endforeach
                <flux:table.column align="end"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->features as $featureValue => $minimumTier)
                    @php($planFeature = PlanFeature::from($featureValue))
                    <flux:table.row :key="$featureValue">
                        <flux:table.cell>
                            <span class="font-medium text-zinc-800 dark:text-white">{{ $planFeature->label() }}</span>
                            <flux:text size="sm" class="max-md:hidden">{{ $planFeature->description() }}</flux:text>
                            @if ($minimumTier !== $planFeature->defaultTier())
                                <flux:text size="sm">{{ __('Moved from :tier', ['tier' => $planFeature->defaultTier()->label()]) }}</flux:text>
                            @endif
                        </flux:table.cell>

                        @foreach (PlanTier::cases() as $planTier)
                            <flux:table.cell align="center">
                                @if ($planTier->includes($minimumTier))
                                    <flux:icon.check class="mx-auto size-5 text-green-600 dark:text-green-400" />
                                    <span class="sr-only">{{ __('Included in :tier', ['tier' => $planTier->label()]) }}</span>
                                @else
                                    <span aria-hidden="true" class="text-zinc-400">—</span>
                                    <span class="sr-only">{{ __('Not in :tier', ['tier' => $planTier->label()]) }}</span>
                                @endif
                            </flux:table.cell>
                        @endforeach

                        <flux:table.cell align="end">
                            @can('update', \App\Models\PlanFeatureSetting::class)
                                <flux:button size="xs" variant="ghost" icon="arrows-up-down" inset="top bottom" wire:click="moveFeature('{{ $featureValue }}')" :aria-label="__('Move :feature', ['feature' => $planFeature->label()])" />
                            @endcan
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </section>

    <flux:modal name="plan-form" class="md:w-[28rem]">
        <form wire:submit="savePlan" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editing_id === null ? __('New plan') : __('Edit :name', ['name' => $name]) }}</flux:heading>
                <flux:text class="mt-2">
                    @if ($editing_id === null)
                        {{ __('Agencies put on this plan get its tier\'s features and are billed this amount every period.') }}
                    @else
                        {{ __('The new terms apply to agencies put on the plan from now on.') }}
                    @endif
                </flux:text>
            </div>

            <flux:input wire:model="name" :label="__('Plan name')" required />

            <flux:select wire:model="tier" :label="__('Tier')">
                @foreach (PlanTier::cases() as $planTier)
                    <flux:select.option :value="$planTier->value">{{ $planTier->label() }} · {{ $planTier->pitch() }}</flux:select.option>
                @endforeach
            </flux:select>

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

    <flux:modal name="move-feature" class="md:w-[28rem]">
        @if ($moving_feature !== null)
            @php($movingFeature = PlanFeature::from($moving_feature))
            <form wire:submit="saveFeatureMove" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Move :feature', ['feature' => $movingFeature->label()]) }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Choose the lowest tier that includes it. Every higher tier includes it too.') }}</flux:text>
                </div>

                <flux:select wire:model.live="minimum_tier" :label="__('Starts at')">
                    @foreach (PlanTier::cases() as $planTier)
                        <flux:select.option :value="$planTier->value">{{ $planTier->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($this->movingUp)
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.heading>{{ trans_choice('{0} No live agency loses it.|{1} 1 live agency loses it.|[2,*] :count live agencies lose it.', $this->agenciesLosing, ['count' => $this->agenciesLosing]) }}</flux:callout.heading>
                        <flux:callout.text>{{ __('Agencies on a lower tier lose it straight away. What they made with it stays, read-only or hidden.') }}</flux:callout.text>
                    </flux:callout>

                    <flux:textarea wire:model="move_reason" :label="__('Reason')" rows="3" required />
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" :variant="$this->movingUp ? 'danger' : 'primary'">{{ __('Move') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
