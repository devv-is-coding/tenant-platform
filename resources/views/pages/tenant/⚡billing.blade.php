<?php

use App\Actions\Subscriptions\RequestPlanChangeAction;
use App\Enums\PlanFeature;
use App\Enums\PlanTier;
use App\Models\PlanChangeRequest;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\Plans\PlanFeatureMatrix;
use App\ValueObjects\Money;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
| The agency's plan and what it owes the platform: its live plan with the
| price, what the plan includes and what a higher tier would add, and its
| invoices and payments, all central data the platform owns, read through
| the agency's own relations, never another agency's.
|
| The one thing the Admin can do here is ask for a plan: an agency cannot
| pick its own, so it asks and a super admin approves or declines
| (RequestPlanChangeAction). One request waits at a time, and the Admin can
| withdraw it. The outcome of each shows here.
|
| Its Admin's alone (TenantPolicy::viewBilling), so managers and staff never
| see what the agency pays. In any status and on any plan: an agency always
| sees its own plan and what it owes.
|
| What each tier includes is read live from PlanFeatureMatrix, so a feature
| the super admin moves shows on the next page load.
|
| Every read re-checks the policy, since Livewire update requests (paging)
| do not re-run the route middleware's role checks on their own.
*/
new #[Title('Plan & billing')] class extends Component {
    use WithPagination;

    /**
     * Rows on each page of invoices and of payments.
     */
    private const PER_PAGE = 10;

    /**
     * The number of closed plan requests listed, newest first.
     */
    private const RECENT_PLAN_REQUESTS_LIMIT = 5;

    /**
     * The plan the request form asks for, as the select hands it back.
     */
    public string $subscription_plan_id = '';

    public string $reason = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewBilling', $this->currentAgency());
    }

    /**
     * Get the agency the page is for.
     */
    #[Computed]
    public function agency(): Tenant
    {
        $agency = $this->currentAgency();
        $this->authorize('viewBilling', $agency);

        return $agency;
    }

    /**
     * Get the subscription holding the agency's live slot, if it has one.
     */
    #[Computed]
    public function subscription(): ?Subscription
    {
        return $this->agency->liveSubscription()->first();
    }

    /**
     * Get every feature the agency's plan includes now.
     *
     * @return list<PlanFeature>
     */
    #[Computed]
    public function features(): array
    {
        return $this->agency->load('liveSubscription')->features();
    }

    /**
     * Get the features each tier above the agency's adds, tier by tier: every
     * tier when the agency has no plan. A tier that adds nothing is left out.
     *
     * @return list<array{tier: PlanTier, features: list<PlanFeature>}>
     */
    #[Computed]
    public function upgrades(): array
    {
        $current = $this->subscription?->tier;
        $minimumTiers = app(PlanFeatureMatrix::class)->minimumTiers();
        $upgrades = [];

        foreach (PlanTier::cases() as $tier) {
            if ($current !== null && $current->includes($tier)) {
                continue;
            }

            $features = array_values(array_filter(
                PlanFeature::cases(),
                fn (PlanFeature $feature): bool => $minimumTiers[$feature->value] === $tier,
            ));

            if ($features !== []) {
                $upgrades[] = ['tier' => $tier, 'features' => $features];
            }
        }

        return $upgrades;
    }

    /**
     * Get what the agency still owes across every invoice it has not settled.
     */
    #[Computed]
    public function balanceOwed(): Money
    {
        return $this->agency->balanceOwed();
    }

    /**
     * Get one page of the agency's invoices, newest period first.
     *
     * @return LengthAwarePaginator<int, SubscriptionInvoice>
     */
    #[Computed]
    public function invoices(): LengthAwarePaginator
    {
        return $this->agency->subscriptionInvoices()
            ->latest('period_start_on')
            ->latest('id')
            ->paginate(self::PER_PAGE, pageName: 'invoices');
    }

    /**
     * Get one page of the agency's payments, most recently paid first, with
     * the invoice each one paid.
     *
     * @return LengthAwarePaginator<int, SubscriptionPayment>
     */
    #[Computed]
    public function payments(): LengthAwarePaginator
    {
        return $this->agency->subscriptionPayments()
            ->with('invoice')
            ->latest('paid_at')
            ->latest('id')
            ->paginate(self::PER_PAGE, pageName: 'payments');
    }

    /**
     * Get the plans in the price list the agency can ask for, by tier.
     *
     * @return Collection<int, SubscriptionPlan>
     */
    #[Computed]
    public function plans(): Collection
    {
        $this->authorize('viewBilling', $this->agency);

        return SubscriptionPlan::query()
            ->when($this->subscription?->subscription_plan_id, fn ($query, int $currentPlanId) => $query->whereKeyNot($currentPlanId))
            ->orderBy('tier')
            ->orderBy('amount_minor')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get the request waiting for the platform's decision, if there is one.
     */
    #[Computed]
    public function pendingPlanRequest(): ?PlanChangeRequest
    {
        return PlanChangeRequest::query()->with('plan')->where('pending_for_tenant_id', $this->agency->id)->first();
    }

    /**
     * Get the agency's most recently closed plan requests, with their
     * outcome.
     *
     * @return Collection<int, PlanChangeRequest>
     */
    #[Computed]
    public function closedPlanRequests(): Collection
    {
        return PlanChangeRequest::query()
            ->with('plan')
            ->where('tenant_id', $this->agency->id)
            ->whereNull('pending_for_tenant_id')
            ->latest('decided_at')
            ->latest('id')
            ->limit(self::RECENT_PLAN_REQUESTS_LIMIT)
            ->get();
    }

    /**
     * Describe what the plan chosen in the form would change: the features
     * the agency would gain, and the ones it would lose.
     *
     * @return array{plan: SubscriptionPlan, adds: list<PlanFeature>, removes: list<PlanFeature>}|null
     */
    #[Computed]
    public function planChange(): ?array
    {
        $plan = ctype_digit($this->subscription_plan_id) ? $this->plans->firstWhere('id', (int) $this->subscription_plan_id) : null;

        if (! $plan instanceof SubscriptionPlan) {
            return null;
        }

        $chosen = app(PlanFeatureMatrix::class)->featuresFor($plan->tier);

        return [
            'plan' => $plan,
            'adds' => array_values(array_filter($chosen, fn (PlanFeature $feature): bool => ! in_array($feature, $this->features, true))),
            'removes' => array_values(array_filter($this->features, fn (PlanFeature $feature): bool => ! in_array($feature, $chosen, true))),
        ];
    }

    /**
     * Open the form for asking for a plan.
     */
    public function openPlanRequest(): void
    {
        $this->authorize('create', [PlanChangeRequest::class, $this->agency]);

        $this->resetErrorBag();
        $this->reset('subscription_plan_id', 'reason');

        Flux::modal('request-plan')->show();
    }

    /**
     * Ask the platform to put the agency on the chosen plan.
     * RequestPlanChangeAction authorizes itself as well.
     */
    public function sendPlanRequest(RequestPlanChangeAction $requestPlanChange): void
    {
        $request = $requestPlanChange->handle(Auth::user(), $this->agency, $this->only(array_keys($requestPlanChange->rules())));

        $this->reset('subscription_plan_id', 'reason');
        unset($this->pendingPlanRequest);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __('You asked for :plan. The platform will put the agency on it or tell you why not.', ['plan' => $request->plan?->name]));
    }

    /**
     * Withdraw the request waiting for a decision.
     */
    public function withdrawPlanRequest(RequestPlanChangeAction $requestPlanChange): void
    {
        $request = $this->pendingPlanRequest ?? abort(404);

        $requestPlanChange->withdraw(Auth::user(), $request);

        unset($this->pendingPlanRequest, $this->closedPlanRequests);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __('Your plan request was withdrawn.'));
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
        <flux:heading size="xl" level="1">{{ __('Plan & billing') }}</flux:heading>
        <flux:text class="mt-1">{{ __(':tenant\'s plan with the platform, what it includes, and what has been billed and paid.', ['tenant' => $this->agency->name ?? $this->agency->id]) }}</flux:text>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- The live plan --}}
        <flux:card class="flex flex-col gap-4" data-test="plan-card">
            <flux:heading size="lg" level="2">{{ __('Plan') }}</flux:heading>

            @if ($this->subscription)
                <div class="flex items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading>{{ $this->subscription->plan_name }}</flux:heading>
                        <flux:badge :color="$this->subscription->tier->color()" size="sm">{{ $this->subscription->tier->label() }}</flux:badge>
                    </div>
                    <flux:badge :color="$this->subscription->status->color()" size="sm">{{ $this->subscription->status->label() }}</flux:badge>
                </div>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Price') }}</dt>
                    <dd>{{ Money::ofMinor($this->subscription->amount_minor, $this->subscription->currency)->format() }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Billed') }}</dt>
                    <dd>{{ $this->subscription->interval->label() }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Started') }}</dt>
                    <dd>{{ $this->subscription->starts_on->toFormattedDateString() }}</dd>

                    @if ($this->subscription->next_billing_on)
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Next billing') }}</dt>
                        <dd>{{ $this->subscription->next_billing_on->toFormattedDateString() }}</dd>
                    @endif

                    @if ($this->subscription->ends_on)
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Ends') }}</dt>
                        <dd>{{ $this->subscription->ends_on->toFormattedDateString() }}</dd>
                    @endif
                </dl>
            @else
                <div>
                    <flux:heading size="xl">{{ __('No plan') }}</flux:heading>
                    <flux:text size="sm" class="mt-1">{{ __('Only what every agency has is open to you. Ask the platform to put the agency on a plan.') }}</flux:text>
                </div>
            @endif

            @if ($this->pendingPlanRequest)
                <flux:callout icon="clock" :heading="__('You asked for :plan.', ['plan' => $this->pendingPlanRequest->plan?->name])" data-test="pending-plan-request">
                    <flux:callout.text>{{ __('Sent :date. Waiting for the platform\'s decision.', ['date' => $this->pendingPlanRequest->created_at?->toFormattedDateString()]) }}</flux:callout.text>

                    <x-slot name="actions">
                        <flux:modal.trigger name="withdraw-plan-request">
                            <flux:button size="sm" variant="ghost">{{ __('Withdraw') }}</flux:button>
                        </flux:modal.trigger>
                    </x-slot>
                </flux:callout>
            @elseif ($this->plans->isNotEmpty())
                @can('create', [\App\Models\PlanChangeRequest::class, $this->agency])
                    <div>
                        <flux:button size="sm" icon="arrows-right-left" wire:click="openPlanRequest" data-test="ask-for-plan-button">
                            {{ $this->subscription ? __('Ask to change plan') : __('Ask for a plan') }}
                        </flux:button>
                    </div>
                @endcan
            @else
                <flux:text size="sm">{{ __('The platform has no other plans on offer right now.') }}</flux:text>
            @endif
        </flux:card>

        {{-- What the plan includes, and what a higher tier adds --}}
        <flux:card class="flex flex-col gap-4 lg:col-span-2" data-test="features-card">
            <div>
                <flux:heading size="lg" level="2">{{ __('What your plan includes') }}</flux:heading>

                @if ($this->features === [])
                    <flux:text class="mt-2">{{ __('Nothing yet: the agency has no live plan.') }}</flux:text>
                @else
                    <ul class="mt-3 flex flex-wrap gap-1.5">
                        @foreach ($this->features as $feature)
                            <li wire:key="included-{{ $feature->value }}"><flux:badge size="sm" color="zinc">{{ $feature->label() }}</flux:badge></li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @foreach ($this->upgrades as $upgrade)
                <div wire:key="upgrade-{{ $upgrade['tier']->value }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:text>{{ __(':tier adds', ['tier' => $upgrade['tier']->label()]) }}</flux:text>
                        <flux:badge size="sm" :color="$upgrade['tier']->color()">{{ $upgrade['tier']->label() }}</flux:badge>
                    </div>

                    <ul class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($upgrade['features'] as $feature)
                            <li wire:key="upgrade-{{ $upgrade['tier']->value }}-{{ $feature->value }}"><flux:badge size="sm" color="zinc">{{ $feature->label() }}</flux:badge></li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </flux:card>
    </div>

    @if ($this->closedPlanRequests->isNotEmpty())
        <section class="flex flex-col gap-4">
            <flux:heading size="lg" level="2">{{ __('Plan requests') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Asked for') }}</flux:table.column>
                    <flux:table.column class="max-md:hidden">{{ __('Asked') }}</flux:table.column>
                    <flux:table.column>{{ __('Outcome') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->closedPlanRequests as $planRequest)
                        <flux:table.row :key="$planRequest->id">
                            <flux:table.cell class="align-top">
                                <span class="font-medium text-zinc-800 dark:text-white">{{ $planRequest->plan?->name ?? '—' }}</span>
                                @if ($planRequest->reason)
                                    <flux:text size="sm" class="mt-1 max-w-md whitespace-normal">{{ $planRequest->reason }}</flux:text>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="align-top max-md:hidden">{{ $planRequest->created_at?->toFormattedDateString() }}</flux:table.cell>

                            <flux:table.cell class="align-top">
                                <flux:badge :color="$planRequest->status->color()" size="sm" inset="top bottom">{{ $planRequest->status->label() }}</flux:badge>

                                @if ($planRequest->decided_at)
                                    <flux:text size="sm" class="mt-1">{{ $planRequest->decided_at->toFormattedDateString() }}</flux:text>
                                @endif

                                @if ($planRequest->decision_reason)
                                    <flux:text size="sm" class="mt-1 max-w-sm whitespace-normal">{{ __('Reason: :reason', ['reason' => $planRequest->decision_reason]) }}</flux:text>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    @endif

    <section class="flex flex-col gap-4">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <flux:heading size="lg" level="2">{{ __('Invoices') }}</flux:heading>

            <flux:text>
                {{ __('Balance owed') }}:
                <span class="font-medium text-zinc-800 dark:text-white" data-test="balance-owed">{{ $this->balanceOwed->format() }}</span>
            </flux:text>
        </div>

        <flux:table :paginate="$this->invoices">
            <flux:table.columns>
                <flux:table.column>{{ __('Reference') }}</flux:table.column>
                <flux:table.column class="max-lg:hidden">{{ __('Period') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column align="end" class="max-md:hidden">{{ __('Paid') }}</flux:table.column>
                <flux:table.column align="end" class="max-md:hidden">{{ __('Balance') }}</flux:table.column>
                <flux:table.column class="max-sm:hidden">{{ __('Due') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->invoices as $invoice)
                    <flux:table.row :key="$invoice->id">
                        <flux:table.cell variant="strong">{{ $invoice->reference }}</flux:table.cell>
                        <flux:table.cell class="max-lg:hidden">
                            {{ $invoice->period_start_on->toFormattedDateString() }} – {{ $invoice->period_end_on->toFormattedDateString() }}
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ Money::ofMinor($invoice->amount_minor, $invoice->currency)->format() }}</flux:table.cell>
                        <flux:table.cell align="end" class="max-md:hidden">{{ Money::ofMinor($invoice->amount_paid_minor, $invoice->currency)->format() }}</flux:table.cell>
                        <flux:table.cell align="end" class="max-md:hidden">{{ Money::ofMinor($invoice->outstandingMinor(), $invoice->currency)->format() }}</flux:table.cell>
                        <flux:table.cell class="max-sm:hidden">{{ $invoice->due_on->toFormattedDateString() }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                <flux:badge :color="$invoice->status->color()" size="sm" inset="top bottom">{{ $invoice->status->label() }}</flux:badge>

                                @if ($invoice->isOverdue(today()))
                                    <flux:badge color="red" size="sm" inset="top bottom">{{ __('Overdue') }}</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7" class="py-8 text-center">{{ __('The platform has not issued the agency any invoices.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <section class="flex flex-col gap-4">
        <flux:heading size="lg" level="2">{{ __('Payments') }}</flux:heading>

        <flux:table :paginate="$this->payments">
            <flux:table.columns>
                <flux:table.column>{{ __('Reference') }}</flux:table.column>
                <flux:table.column class="max-md:hidden">{{ __('Invoice') }}</flux:table.column>
                <flux:table.column class="max-sm:hidden">{{ __('Method') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column class="max-sm:hidden">{{ __('Paid on') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->payments as $payment)
                    <flux:table.row :key="$payment->id">
                        <flux:table.cell variant="strong">
                            {{ $payment->reference }}

                            @unless ($payment->status->countsAsPaid())
                                <flux:badge color="red" size="sm" inset="top bottom" class="ms-1">{{ $payment->status->label() }}</flux:badge>
                            @endunless
                        </flux:table.cell>
                        <flux:table.cell class="max-md:hidden">{{ $payment->invoice?->reference ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="max-sm:hidden">{{ $payment->method->label() }}</flux:table.cell>
                        <flux:table.cell align="end">{{ Money::ofMinor($payment->amount_minor, $payment->currency)->format() }}</flux:table.cell>
                        <flux:table.cell class="max-sm:hidden">{{ $payment->paid_at?->toFormattedDateString() ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="py-8 text-center">{{ __('The agency has not paid the platform anything yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <flux:modal name="request-plan" class="md:w-[32rem]">
        <form wire:submit="sendPlanRequest" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $this->subscription ? __('Ask to change plan') : __('Ask for a plan') }}</flux:heading>
                <flux:text class="mt-2">{{ __('The platform puts the agency on it, starting the day it approves, or tells you why not.') }}</flux:text>
            </div>

            <flux:select wire:model.live="subscription_plan_id" :label="__('Plan')" :placeholder="__('Choose a plan…')" required>
                @foreach ($this->plans as $plan)
                    <flux:select.option :value="$plan->id" wire:key="plan-{{ $plan->id }}">
                        {{ $plan->name }} · {{ $plan->tier->label() }} · {{ Money::ofMinor($plan->amount_minor, $plan->currency)->format() }} / {{ mb_strtolower($plan->interval->label()) }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->planChange)
                <div class="flex flex-col gap-3" data-test="plan-change-preview">
                    @if ($this->planChange['adds'] !== [])
                        <div>
                            <flux:text size="sm" class="mb-1">{{ __('You would gain') }}</flux:text>
                            <ul class="flex flex-wrap gap-1.5">
                                @foreach ($this->planChange['adds'] as $feature)
                                    <li wire:key="adds-{{ $feature->value }}"><flux:badge size="sm" color="green">{{ $feature->label() }}</flux:badge></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($this->planChange['removes'] !== [])
                        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('You would lose')">
                            <flux:callout.text>{{ implode(', ', array_map(fn ($feature) => $feature->label(), $this->planChange['removes'])) }}</flux:callout.text>
                            <flux:callout.text>{{ __('What you made with them stays, read-only or hidden.') }}</flux:callout.text>
                        </flux:callout>
                    @endif

                    @if ($this->planChange['adds'] === [] && $this->planChange['removes'] === [])
                        <flux:text size="sm">{{ __('The same features as now, at this plan\'s price and billing.') }}</flux:text>
                    @endif
                </div>
            @endif

            <flux:textarea wire:model="reason" :label="__('Note to the platform')" :placeholder="__('Optional')" rows="3" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Send request') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="withdraw-plan-request" class="md:w-[28rem]">
        <form wire:submit="withdrawPlanRequest" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Withdraw your plan request?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('The platform will not decide on it. You can ask for a plan again afterwards.') }}</flux:text>
            </div>

            <flux:error name="withdraw" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="danger">{{ __('Withdraw') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
