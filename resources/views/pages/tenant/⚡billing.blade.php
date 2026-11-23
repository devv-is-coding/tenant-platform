<?php

use App\Actions\Subscriptions\ManageAutoPayAction;
use App\Actions\Subscriptions\RequestPlanChangeAction;
use App\Actions\Subscriptions\SchedulePlanChangeAction;
use App\Enums\PlanFeature;
use App\Enums\PlanTier;
use App\Models\PlanChangeRequest;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\Billing\PlatformCheckout;
use App\Services\Plans\PlanFeatureMatrix;
use App\ValueObjects\Money;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Stripe\Exception\ApiErrorException;

/*
| The agency's plan and what it owes the platform: its live plan with the
| price, what the plan includes and what a higher tier would add, and its
| invoices and payments, all central data the platform owns, read through
| the agency's own relations, never another agency's.
|
| The Admin can change plan by card (TA-27): a move up is paid through Stripe
| Checkout, less the unused days already paid, and starts once Stripe
| confirms it (the webhook); any other move waits for the paid period's end
| (SchedulePlanChangeAction). An open invoice is paid by card the same way.
| With a card saved and auto-pay on, new invoices are charged to it the day
| they are issued, one retry three days later (TA-33, ManageAutoPayAction).
| Anything off the price list's terms is still asked for: a super admin
| approves or declines (RequestPlanChangeAction). One request waits at a
| time, and the Admin can withdraw it. The outcome of each shows here.
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
     * The plan the change-plan form moves to, as the select hands it back.
     */
    public string $change_plan_id = '';

    /**
     * How the Admin came back from Stripe Checkout: `paid` or `cancelled`.
     */
    #[Url(except: '')]
    public string $checkout = '';

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

        return ['plan' => $plan, ...$this->featureChangeFor($plan)];
    }

    /**
     * Describe the plan chosen in the change-plan form: what a move up costs
     * now (its price less the credit for the unused, paid days of the current
     * plan), or when any other move happens, and the features it changes.
     *
     * @return array{plan: SubscriptionPlan, upgrade: bool, amount: Money|null, credit: Money|null, error: string|null, adds: list<PlanFeature>, removes: list<PlanFeature>}|null
     */
    #[Computed]
    public function planQuote(): ?array
    {
        $plan = ctype_digit($this->change_plan_id) ? $this->plans->firstWhere('id', (int) $this->change_plan_id) : null;

        if (! $plan instanceof SubscriptionPlan) {
            return null;
        }

        $quote = ['plan' => $plan, 'upgrade' => PlatformCheckout::isUpgrade($this->subscription, $plan), 'amount' => null, 'credit' => null, 'error' => null, ...$this->featureChangeFor($plan)];

        if (! $quote['upgrade']) {
            return $quote;
        }

        try {
            return [...$quote, ...app(PlatformCheckout::class)->quote($this->agency, $plan, today())];
        } catch (ValidationException $refused) {
            return [...$quote, 'error' => $refused->getMessage()];
        }
    }

    /**
     * Get the plan the agency moves to when the paid period ends, if the
     * Admin chose one.
     */
    #[Computed]
    public function scheduledPlan(): ?SubscriptionPlan
    {
        return $this->subscription?->scheduled_plan_id === null ? null : $this->subscription->scheduledPlan()->first();
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
     * Open the form for changing plan by card.
     */
    public function openPlanChange(): void
    {
        $this->authorize('manageBilling', $this->agency);

        $this->resetErrorBag();
        $this->reset('change_plan_id');

        Flux::modal('change-plan')->show();
    }

    /**
     * Move to the chosen plan. A move up goes to Stripe Checkout to be paid,
     * and starts once Stripe confirms it; any other move waits for the paid
     * period to end.
     */
    public function changePlan(PlatformCheckout $checkout, SchedulePlanChangeAction $schedulePlanChange): void
    {
        $this->authorize('manageBilling', $this->agency);

        $plan = ctype_digit($this->change_plan_id) ? $this->plans->firstWhere('id', (int) $this->change_plan_id) : null;

        if (! $plan instanceof SubscriptionPlan) {
            throw ValidationException::withMessages(['plan' => __('Choose a plan.')]);
        }

        if (PlatformCheckout::isUpgrade($this->subscription, $plan)) {
            $this->redirectToStripe(fn (): string => $checkout->planUrl($this->agency, $plan, Auth::user()));

            return;
        }

        $subscription = $schedulePlanChange->schedule(Auth::user(), $this->agency, $plan);

        unset($this->subscription, $this->scheduledPlan);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __('The agency moves to :plan on :date.', [
            'plan' => $plan->name,
            'date' => $subscription->next_billing_on?->toFormattedDateString(),
        ]));
    }

    /**
     * Keep the agency on its plan: drop the move waiting for the period's end.
     */
    public function cancelScheduledChange(SchedulePlanChangeAction $schedulePlanChange): void
    {
        $this->authorize('manageBilling', $this->agency);

        $schedulePlanChange->unschedule(Auth::user(), $this->agency);

        unset($this->subscription, $this->scheduledPlan);
        Flux::toast(variant: 'success', text: __('The agency stays on its plan.'));
    }

    /**
     * Pay what one of the agency's invoices still owes, by card.
     */
    public function payInvoice(int $invoiceId, PlatformCheckout $checkout): void
    {
        $this->authorize('manageBilling', $this->agency);

        $invoice = $this->agency->subscriptionInvoices()->whereKey($invoiceId)->firstOrFail();

        $this->redirectToStripe(fn (): string => $checkout->invoiceUrl($invoice, Auth::user()));
    }

    /**
     * Describe the agency's saved card, e.g. "Visa ending 4242", if it has one.
     */
    #[Computed]
    public function savedCard(): ?string
    {
        return $this->agency->stripe_payment_method_id === null
            ? null
            : app(ManageAutoPayAction::class)->describeCard($this->agency->card_brand, $this->agency->card_last4);
    }

    /**
     * Save a card for auto-pay on Stripe's page, or replace the saved one.
     */
    public function saveCard(PlatformCheckout $checkout): void
    {
        $this->authorize('manageBilling', $this->agency);

        $this->redirectToStripe(fn (): string => $checkout->cardUrl($this->agency, Auth::user()));
    }

    /**
     * Have new invoices charged to the saved card.
     */
    public function turnOnAutoPay(ManageAutoPayAction $manageAutoPay): void
    {
        $this->authorize('manageBilling', $this->agency);

        $manageAutoPay->turnOn(Auth::user(), $this->agency);

        Flux::toast(variant: 'success', text: __('Auto-pay is on: each new invoice is charged to :card the day it is issued.', ['card' => $this->savedCard]));
    }

    /**
     * Stop charging the saved card; invoices are paid by hand again.
     */
    public function turnOffAutoPay(ManageAutoPayAction $manageAutoPay): void
    {
        $this->authorize('manageBilling', $this->agency);

        $manageAutoPay->turnOff(Auth::user(), $this->agency);

        Flux::toast(variant: 'success', text: __('Auto-pay is off. Pay new invoices from this page.'));
    }

    /**
     * Forget the saved card, which turns auto-pay off.
     */
    public function removeCard(ManageAutoPayAction $manageAutoPay): void
    {
        $this->authorize('manageBilling', $this->agency);

        $manageAutoPay->removeCard(Auth::user(), $this->agency);

        unset($this->savedCard);
        Flux::toast(variant: 'success', text: __('The card is removed, and auto-pay is off.'));
    }

    /**
     * Send the Admin to Stripe's payment page, or say Stripe could not open it.
     *
     * @param  \Closure(): string  $url
     */
    private function redirectToStripe(\Closure $url): void
    {
        try {
            $this->redirect($url());
        } catch (ApiErrorException) {
            Flux::toast(variant: 'danger', text: __('Stripe could not open the payment page. Try again in a moment.'));
        }
    }

    /**
     * Describe the features moving to the plan would add and take away.
     *
     * @return array{adds: list<PlanFeature>, removes: list<PlanFeature>}
     */
    private function featureChangeFor(SubscriptionPlan $plan): array
    {
        $chosen = app(PlanFeatureMatrix::class)->featuresFor($plan->tier);

        return [
            'adds' => array_values(array_filter($chosen, fn (PlanFeature $feature): bool => ! in_array($feature, $this->features, true))),
            'removes' => array_values(array_filter($this->features, fn (PlanFeature $feature): bool => ! in_array($feature, $chosen, true))),
        ];
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

    @if ($checkout === 'paid')
        <flux:callout variant="success" icon="check-circle" :heading="__('Payment received.')" data-test="checkout-paid">
            <flux:callout.text>{{ __('Your plan and invoices update here as soon as Stripe confirms it, usually within a minute.') }}</flux:callout.text>

            <x-slot name="controls">
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('checkout', '')" :aria-label="__('Dismiss')" />
            </x-slot>
        </flux:callout>
    @elseif ($checkout === 'card')
        <flux:callout variant="success" icon="credit-card" :heading="__('Card saved.')" data-test="checkout-card">
            <flux:callout.text>{{ __('Auto-pay turns on as soon as Stripe confirms the card, usually within a minute.') }}</flux:callout.text>

            <x-slot name="controls">
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('checkout', '')" :aria-label="__('Dismiss')" />
            </x-slot>
        </flux:callout>
    @elseif ($checkout === 'cancelled')
        <flux:callout icon="information-circle" :heading="__('Payment cancelled. Nothing was charged.')" data-test="checkout-cancelled">
            <x-slot name="controls">
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('checkout', '')" :aria-label="__('Dismiss')" />
            </x-slot>
        </flux:callout>
    @endif

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

            @if ($this->scheduledPlan)
                <flux:callout icon="calendar" :heading="__('Moving to :plan on :date.', ['plan' => $this->scheduledPlan->name, 'date' => $this->subscription?->next_billing_on?->toFormattedDateString()])" data-test="scheduled-plan-change">
                    <flux:callout.text>{{ __('The agency keeps :plan until the paid period ends.', ['plan' => $this->subscription?->plan_name]) }}</flux:callout.text>

                    @can('manageBilling', $this->agency)
                        <x-slot name="actions">
                            <flux:button size="sm" variant="ghost" wire:click="cancelScheduledChange" data-test="keep-plan-button">{{ __('Keep current plan') }}</flux:button>
                        </x-slot>
                    @endcan
                </flux:callout>
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
                <div class="flex flex-wrap gap-2">
                    @can('manageBilling', $this->agency)
                        <flux:button size="sm" variant="primary" icon="credit-card" wire:click="openPlanChange" data-test="change-plan-button">
                            {{ $this->subscription ? __('Change plan') : __('Buy a plan') }}
                        </flux:button>
                    @endcan

                    @can('create', [\App\Models\PlanChangeRequest::class, $this->agency])
                        <flux:button size="sm" icon="arrows-right-left" wire:click="openPlanRequest" data-test="ask-for-plan-button">
                            {{ $this->subscription ? __('Ask to change plan') : __('Ask for a plan') }}
                        </flux:button>
                    @endcan
                </div>
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

    {{-- Auto-pay: the saved card, and whether new invoices are charged to it --}}
    <flux:card class="flex flex-col gap-4" data-test="auto-pay-card">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="lg" level="2">{{ __('Auto-pay') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Each new invoice is charged to the saved card the day it is issued, tried once more three days later if that fails. You can always pay by hand.') }}</flux:text>
            </div>

            <flux:badge :color="$this->agency->hasAutoPay() ? 'green' : 'zinc'" size="sm" data-test="auto-pay-status">
                {{ $this->agency->hasAutoPay() ? __('On') : __('Off') }}
            </flux:badge>
        </div>

        @if ($this->savedCard)
            <flux:text>{{ __('Saved card: :card', ['card' => $this->savedCard]) }}</flux:text>
        @endif

        <flux:error name="auto_pay" />

        @can('manageBilling', $this->agency)
            <div class="flex flex-wrap gap-2">
                @if ($this->savedCard === null)
                    <flux:button size="sm" variant="primary" icon="credit-card" wire:click="saveCard" data-test="save-card-button">{{ __('Save a card') }}</flux:button>
                @else
                    @if ($this->agency->hasAutoPay())
                        <flux:button size="sm" wire:click="turnOffAutoPay" data-test="auto-pay-off-button">{{ __('Turn off auto-pay') }}</flux:button>
                    @else
                        <flux:button size="sm" variant="primary" wire:click="turnOnAutoPay" data-test="auto-pay-on-button">{{ __('Turn on auto-pay') }}</flux:button>
                    @endif

                    <flux:button size="sm" variant="ghost" icon="credit-card" wire:click="saveCard" data-test="replace-card-button">{{ __('Replace card') }}</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="removeCard" data-test="remove-card-button">{{ __('Remove card') }}</flux:button>
                @endif
            </div>
        @endcan
    </flux:card>

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
                <flux:table.column align="end"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->invoices as $invoice)
                    <flux:table.row :key="$invoice->id">
                        <flux:table.cell variant="strong">{{ $invoice->reference }}</flux:table.cell>
                        <flux:table.cell class="max-lg:hidden">
                            {{ $invoice->period_start_on->toFormattedDateString() }} – {{ $invoice->period_end_on->toFormattedDateString() }}
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            {{ Money::ofMinor($invoice->amount_minor, $invoice->currency)->format() }}

                            @if ($invoice->credit_minor > 0)
                                <flux:text size="sm">{{ __('after :credit credit', ['credit' => Money::ofMinor($invoice->credit_minor, $invoice->currency)->format()]) }}</flux:text>
                            @endif
                        </flux:table.cell>
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
                        <flux:table.cell align="end">
                            @if ($invoice->status->isOutstanding() && $invoice->outstandingMinor() > 0)
                                @can('manageBilling', $this->agency)
                                    <flux:button size="sm" icon="credit-card" inset="top bottom" wire:click="payInvoice({{ $invoice->id }})" data-test="pay-invoice-{{ $invoice->id }}">{{ __('Pay') }}</flux:button>
                                @endcan
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8" class="py-8 text-center">{{ __('The platform has not issued the agency any invoices.') }}</flux:table.cell>
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
                <x-tenant.plan-change-preview :adds="$this->planChange['adds']" :removes="$this->planChange['removes']" />
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

    <flux:modal name="change-plan" class="md:w-[32rem]">
        <form wire:submit="changePlan" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $this->subscription ? __('Change plan') : __('Buy a plan') }}</flux:heading>
                <flux:text class="mt-2">{{ __('A move up is paid by card now and starts at once, less the days you already paid for. Any other move starts when the paid period ends.') }}</flux:text>
            </div>

            <flux:select wire:model.live="change_plan_id" :label="__('Plan')" :placeholder="__('Choose a plan…')" required>
                @foreach ($this->plans as $plan)
                    <flux:select.option :value="$plan->id" wire:key="change-plan-{{ $plan->id }}">
                        {{ $plan->name }} · {{ $plan->tier->label() }} · {{ Money::ofMinor($plan->amount_minor, $plan->currency)->format() }} / {{ mb_strtolower($plan->interval->label()) }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:error name="plan" />

            @if ($this->planQuote)
                <div class="flex flex-col gap-4" data-test="plan-quote">
                    @if ($this->planQuote['error'])
                        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$this->planQuote['error']" />
                    @elseif ($this->planQuote['upgrade'])
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Price') }}</dt>
                            <dd>{{ Money::ofMinor($this->planQuote['plan']->amount_minor, $this->planQuote['plan']->currency)->format() }}</dd>

                            @if ($this->planQuote['credit']?->minor > 0)
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Credit for unused days') }}</dt>
                                <dd>−{{ $this->planQuote['credit']->format() }}</dd>
                            @endif

                            <dt class="font-medium text-zinc-800 dark:text-white">{{ __('To pay now') }}</dt>
                            <dd class="font-medium text-zinc-800 dark:text-white" data-test="amount-due">{{ $this->planQuote['amount']?->format() }}</dd>
                        </dl>
                    @else
                        <flux:text size="sm">{{ __('Starts on :date. Until then the agency keeps :plan.', ['date' => $this->subscription?->next_billing_on?->toFormattedDateString(), 'plan' => $this->subscription?->plan_name]) }}</flux:text>
                    @endif

                    <x-tenant.plan-change-preview :adds="$this->planQuote['adds']" :removes="$this->planQuote['removes']" />
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">
                    {{ $this->planQuote && ! $this->planQuote['upgrade'] ? __('Schedule the move') : __('Pay by card') }}
                </flux:button>
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
