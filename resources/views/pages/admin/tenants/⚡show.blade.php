<?php

use App\Actions\Subscriptions\RecordSubscriptionPaymentAction;
use App\Actions\Subscriptions\StartSubscriptionAction;
use App\Actions\Tenants\ChangeTenantStatusAction;
use App\Enums\BillingInterval;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\TenantStatus;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\ValueObjects\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
| An agency's platform record. Everything here is read from the central
| database - its staff accounts, its subscription and the platform's audit
| trail about it. The super admin never reads the agency's own database.
*/
new class extends Component {
    /**
     * The number of audit events listed, newest first.
     */
    private const RECENT_ACTIVITY_LIMIT = 10;

    /**
     * The number of invoices listed, newest period first.
     */
    private const RECENT_INVOICES_LIMIT = 10;

    /**
     * The number of payments listed, most recently paid first.
     */
    private const RECENT_PAYMENTS_LIMIT = 10;

    public Tenant $tenant;

    public string $status_reason = '';

    public string $plan_name = '';

    public string $amount = '';

    public int $interval = BillingInterval::Monthly->value;

    /**
     * The invoice the payment form records against. Set only by
     * openPaymentForm(), never by the browser.
     */
    #[Locked]
    public ?int $paying_invoice_id = null;

    public string $payment_amount = '';

    public int $payment_method = PaymentMethod::BankTransfer->value;

    public string $payment_paid_on = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('view', $this->tenant);
    }

    /**
     * Move the agency to the given status.
     *
     * Authorized here because Livewire's update requests do not re-run the
     * console's route middleware.
     */
    public function changeStatus(int $status, ChangeTenantStatusAction $changeTenantStatus): void
    {
        $this->authorize('changeStatus', $this->tenant);

        $target = TenantStatus::tryFrom($status)
            ?? throw ValidationException::withMessages(['status' => __('That is not an agency status.')]);

        $this->tenant = $changeTenantStatus->handle(Auth::user(), $this->tenant, $target, $this->only('status_reason'));

        $this->reset('status_reason');
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __(':name is now :status.', [
            'name' => $this->tenant->name ?? $this->tenant->id,
            'status' => mb_strtolower($this->tenant->status->label()),
        ]));
    }

    /**
     * Put the agency on a plan, replacing the one it is on.
     *
     * Authorized here because Livewire's update requests do not re-run the
     * console's route middleware.
     */
    public function startSubscription(StartSubscriptionAction $startSubscription): void
    {
        $this->authorize('manageSubscription', $this->tenant);

        $subscription = $startSubscription->handle(Auth::user(), $this->tenant, $this->only(['plan_name', 'amount', 'interval']));

        $this->reset('plan_name', 'amount', 'interval');
        unset($this->subscription, $this->subscriptions, $this->recentActivity);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __(':name is now on :plan.', [
            'name' => $this->tenant->name ?? $this->tenant->id,
            'plan' => $subscription->plan_name,
        ]));
    }

    /**
     * Open the payment form for one of the agency's invoices, filled in with
     * the full balance still owed and today's date.
     */
    public function openPaymentForm(int $invoiceId): void
    {
        $invoice = $this->tenant->subscriptionInvoices()->findOrFail($invoiceId);
        $this->authorize('recordPayment', $invoice);

        $this->resetErrorBag();
        $this->paying_invoice_id = $invoice->id;
        $this->payment_amount = Money::ofMinor($invoice->outstandingMinor(), $invoice->currency)->toDecimal();
        $this->payment_method = PaymentMethod::BankTransfer->value;
        $this->payment_paid_on = today()->toDateString();
        unset($this->payingInvoice);

        Flux::modal('record-payment')->show();
    }

    /**
     * Record a payment received by hand against the invoice the form is open for.
     *
     * The invoice is looked up through this agency, so a tampered request
     * cannot reach another agency's invoice. Authorized here because
     * Livewire's update requests do not re-run the console's route middleware.
     */
    public function recordPayment(RecordSubscriptionPaymentAction $recordSubscriptionPayment): void
    {
        $invoice = $this->tenant->subscriptionInvoices()->findOrFail($this->paying_invoice_id);
        $this->authorize('recordPayment', $invoice);

        try {
            $payment = $recordSubscriptionPayment->handle(Auth::user(), $invoice, [
                'amount' => $this->payment_amount,
                'method' => $this->payment_method,
                'paid_on' => $this->payment_paid_on,
            ]);
        } catch (ValidationException $exception) {
            // The action's fields share names with the plan form's, so its
            // errors are moved onto this form's fields.
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ["payment_{$field}" => $messages])
                ->all());
        }

        $this->reset('paying_invoice_id', 'payment_amount', 'payment_method', 'payment_paid_on');
        unset($this->subscription, $this->invoices, $this->payments, $this->balanceOwed, $this->recentActivity);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __(':amount recorded against :reference.', [
            'amount' => Money::ofMinor($payment->amount_minor, $payment->currency)->format(),
            'reference' => $invoice->reference,
        ]));
    }

    /**
     * Get the status changes open to the agency from where it stands now,
     * with the wording for each confirmation.
     *
     * @return list<array{status: TenantStatus, label: string, heading: string, description: string, reasonRequired: bool, variant: string}>
     */
    #[Computed]
    public function statusActions(): array
    {
        $name = $this->tenant->name ?? $this->tenant->id;

        return collect(TenantStatus::cases())
            ->filter(fn (TenantStatus $status): bool => $this->tenant->status->canTransitionTo($status))
            ->map(fn (TenantStatus $status): array => match (true) {
                $status === TenantStatus::Suspended => [
                    'status' => $status,
                    'label' => __('Suspend'),
                    'heading' => __('Suspend :name?', ['name' => $name]),
                    'description' => __('Its staff keep read-only access, so they can still honour travel already sold. The reason is written into the agency\'s own audit log.'),
                    'reasonRequired' => true,
                    'variant' => 'danger',
                ],
                $this->tenant->status === TenantStatus::Pending => [
                    'status' => $status,
                    'label' => __('Activate'),
                    'heading' => __('Activate :name?', ['name' => $name]),
                    'description' => __('The agency goes live and its staff get full access.'),
                    'reasonRequired' => false,
                    'variant' => 'primary',
                ],
                default => [
                    'status' => $status,
                    'label' => __('Reactivate'),
                    'heading' => __('Reactivate :name?', ['name' => $name]),
                    'description' => __('Its staff get full access back. Payment alone never does this; it is your decision.'),
                    'reasonRequired' => false,
                    'variant' => 'primary',
                ],
            })
            ->values()
            ->all();
    }

    /**
     * Get the agency's admins, managers and staff, most senior first.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return $this->tenant->users()->orderBy('role')->orderBy('first_name')->orderBy('last_name')->get();
    }

    #[Computed]
    public function subscription(): ?Subscription
    {
        return $this->tenant->latestSubscription()->first();
    }

    /**
     * Get every subscription the agency has held, newest first.
     *
     * @return Collection<int, Subscription>
     */
    #[Computed]
    public function subscriptions(): Collection
    {
        return $this->tenant->subscriptions()->latest('id')->get();
    }

    /**
     * Get the agency's newest invoices.
     *
     * @return Collection<int, SubscriptionInvoice>
     */
    #[Computed]
    public function invoices(): Collection
    {
        return $this->tenant->subscriptionInvoices()
            ->latest('period_start_on')
            ->latest('id')
            ->limit(self::RECENT_INVOICES_LIMIT)
            ->get();
    }

    /**
     * Get what the agency still owes across every invoice it has not settled.
     *
     * Summed in minor units by the database, and only ever displayed through
     * Money. Every invoice is billed in the platform's billing currency.
     */
    #[Computed]
    public function balanceOwed(): Money
    {
        $owedMinor = $this->tenant->subscriptionInvoices()
            ->whereIn('status', [SubscriptionInvoiceStatus::Open, SubscriptionInvoiceStatus::PartiallyPaid])
            ->selectRaw('COALESCE(SUM(amount_minor - amount_paid_minor), 0) as owed_minor')
            ->value('owed_minor');

        return Money::ofMinor((int) $owedMinor, config('tenancy.billing_currency'));
    }

    /**
     * Get the agency's most recent payments, with the invoice each one paid.
     *
     * @return Collection<int, SubscriptionPayment>
     */
    #[Computed]
    public function payments(): Collection
    {
        return $this->tenant->subscriptionPayments()
            ->with(['invoice', 'recorder'])
            ->latest('paid_on')
            ->latest('id')
            ->limit(self::RECENT_PAYMENTS_LIMIT)
            ->get();
    }

    /**
     * Get the invoice the payment form is open for.
     */
    #[Computed]
    public function payingInvoice(): ?SubscriptionInvoice
    {
        return $this->paying_invoice_id === null
            ? null
            : $this->tenant->subscriptionInvoices()->find($this->paying_invoice_id);
    }

    /**
     * @return Collection<int, PlatformAuditEvent>
     */
    #[Computed]
    public function recentActivity(): Collection
    {
        return $this->tenant->platformAuditEvents()
            ->latest('created_on')
            ->latest('id')
            ->limit(self::RECENT_ACTIVITY_LIMIT)
            ->get();
    }

    public function render(): View
    {
        return $this->view()->title($this->tenant->name ?? $this->tenant->id);
    }
}; ?>

<div class="flex flex-col gap-8">
    <div>
        <flux:link :href="route('admin.tenants.index')" variant="subtle" class="inline-flex items-center gap-1 text-sm" wire:navigate>
            <flux:icon.chevron-left variant="micro" />
            {{ __('All agencies') }}
        </flux:link>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1">{{ $tenant->name ?? $tenant->id }}</flux:heading>
                <flux:badge :color="$tenant->status->color()">{{ $tenant->status->label() }}</flux:badge>
            </div>

            @if ($this->statusActions)
                <div class="flex gap-2">
                    @foreach ($this->statusActions as $action)
                        <flux:modal.trigger :name="'change-status-'.$action['status']->value">
                            <flux:button :variant="$action['variant']">{{ $action['label'] }}</flux:button>
                        </flux:modal.trigger>
                    @endforeach
                </div>
            @endif
        </div>

        <flux:text class="mt-2">
            {{ $tenant->domains->pluck('domain')->join(', ') ?: $tenant->id }}
            &middot;
            {{ __('Onboarded :date', ['date' => $tenant->created_on?->toFormattedDateString()]) }}
        </flux:text>

        @if ($tenant->status_reason)
            <flux:callout icon="information-circle" class="mt-4" :heading="__(':status: :reason', ['status' => $tenant->status->label(), 'reason' => $tenant->status_reason])" />
        @endif

        @foreach ($this->statusActions as $action)
            <flux:modal :name="'change-status-'.$action['status']->value" class="md:w-[28rem]" wire:key="change-status-{{ $action['status']->value }}">
                <form wire:submit="changeStatus({{ $action['status']->value }})" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ $action['heading'] }}</flux:heading>
                        <flux:text class="mt-2">{{ $action['description'] }}</flux:text>
                    </div>

                    <flux:textarea
                        wire:model="status_reason"
                        :label="__('Reason')"
                        :badge="$action['reasonRequired'] ? null : __('Optional')"
                        rows="3"
                        :required="$action['reasonRequired']"
                    />

                    <flux:error name="status" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" :variant="$action['variant']">{{ $action['label'] }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endforeach
    </div>

    <div class="grid gap-8 lg:grid-cols-3">
        <section class="flex flex-col gap-4 lg:col-span-2">
            <flux:heading size="lg" level="2">{{ __('Users') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Role') }}</flux:table.column>
                    <flux:table.column class="max-sm:hidden">{{ __('Account') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->users as $user)
                        <flux:table.row :key="$user->id">
                            <flux:table.cell>
                                <span class="font-medium text-zinc-800 dark:text-white">{{ $user->full_name }}</span>
                                <flux:text size="sm">{{ $user->email }}</flux:text>
                            </flux:table.cell>

                            <flux:table.cell>{{ $user->role->label() }}</flux:table.cell>

                            <flux:table.cell class="max-sm:hidden">
                                @if ($user->is_active)
                                    <flux:badge color="green" size="sm" inset="top bottom">{{ __('Active') }}</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm" inset="top bottom">{{ __('Deactivated') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="3" class="py-8 text-center">{{ __('This agency has no users.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </section>

        <aside class="flex flex-col gap-4">
            <div class="flex items-center justify-between gap-3">
                <flux:heading size="lg" level="2">{{ __('Subscription') }}</flux:heading>

                @can('manageSubscription', $tenant)
                    <flux:modal.trigger name="start-subscription">
                        <flux:button size="sm">
                            {{ $this->subscription?->status->isLive() ? __('Change plan') : __('Start subscription') }}
                        </flux:button>
                    </flux:modal.trigger>
                @endcan
            </div>

            <flux:card class="flex flex-col gap-4">
                @if ($this->subscription)
                    <div class="flex items-center justify-between gap-3">
                        <flux:heading>{{ $this->subscription->plan_name }}</flux:heading>
                        <flux:badge :color="$this->subscription->status->color()" size="sm">{{ $this->subscription->status->label() }}</flux:badge>
                    </div>

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Amount') }}</dt>
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
                    <flux:text>{{ __('This agency has never had a subscription.') }}</flux:text>
                @endif
            </flux:card>

            <flux:modal name="start-subscription" class="md:w-[28rem]">
                <form wire:submit="startSubscription" class="space-y-6">
                    <div>
                        <flux:heading size="lg">
                            {{ $this->subscription?->status->isLive() ? __('Change :name\'s plan', ['name' => $tenant->name ?? $tenant->id]) : __('Start a subscription for :name', ['name' => $tenant->name ?? $tenant->id]) }}
                        </flux:heading>
                        <flux:text class="mt-2">
                            @if ($this->subscription?->status->isLive())
                                {{ __('The new plan starts today. :plan ends today and stays in the history.', ['plan' => $this->subscription->plan_name]) }}
                            @else
                                {{ __('The plan starts today. The name, price and billing interval are recorded as agreed.') }}
                            @endif
                        </flux:text>
                    </div>

                    <flux:input wire:model="plan_name" :label="__('Plan name')" :placeholder="__('Agency Standard')" required />

                    <flux:field>
                        <flux:label>{{ __('Amount per billing period') }}</flux:label>

                        <flux:input.group>
                            <flux:input.group.prefix>{{ config('tenancy.billing_currency') }}</flux:input.group.prefix>

                            <flux:input wire:model="amount" inputmode="decimal" placeholder="2500.00" required />
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

                        <flux:button type="submit" variant="primary">
                            {{ $this->subscription?->status->isLive() ? __('Change plan') : __('Start subscription') }}
                        </flux:button>
                    </div>
                </form>
            </flux:modal>
        </aside>

        <section class="flex flex-col gap-4 lg:col-span-3">
            <flux:heading size="lg" level="2">{{ __('Subscription history') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Plan') }}</flux:table.column>
                    <flux:table.column>{{ __('Amount') }}</flux:table.column>
                    <flux:table.column class="max-sm:hidden">{{ __('Status') }}</flux:table.column>
                    <flux:table.column class="max-sm:hidden">{{ __('Started') }}</flux:table.column>
                    <flux:table.column class="max-sm:hidden">{{ __('Ended') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->subscriptions as $pastSubscription)
                        <flux:table.row :key="$pastSubscription->id">
                            <flux:table.cell variant="strong">{{ $pastSubscription->plan_name }}</flux:table.cell>
                            <flux:table.cell>
                                {{ Money::ofMinor($pastSubscription->amount_minor, $pastSubscription->currency)->format() }}
                                <flux:text size="sm">{{ $pastSubscription->interval->label() }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="max-sm:hidden">
                                <flux:badge :color="$pastSubscription->status->color()" size="sm" inset="top bottom">{{ $pastSubscription->status->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="max-sm:hidden">{{ $pastSubscription->starts_on->toFormattedDateString() }}</flux:table.cell>
                            <flux:table.cell class="max-sm:hidden">{{ $pastSubscription->ends_on?->toFormattedDateString() ?? '—' }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="py-8 text-center">{{ __('No subscriptions yet.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </section>

        <section class="flex flex-col gap-4 lg:col-span-3">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <flux:heading size="lg" level="2">{{ __('Invoices') }}</flux:heading>

                <flux:text>
                    {{ __('Balance owed') }}:
                    <span class="font-medium text-zinc-800 dark:text-white">{{ $this->balanceOwed->format() }}</span>
                </flux:text>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Reference') }}</flux:table.column>
                    <flux:table.column class="max-lg:hidden">{{ __('Period') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                    <flux:table.column align="end" class="max-md:hidden">{{ __('Paid') }}</flux:table.column>
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
                            <flux:table.cell align="end">{{ Money::ofMinor($invoice->amount_minor, $invoice->currency)->format() }}</flux:table.cell>
                            <flux:table.cell align="end" class="max-md:hidden">{{ Money::ofMinor($invoice->amount_paid_minor, $invoice->currency)->format() }}</flux:table.cell>
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
                                @if ($invoice->status->acceptsPayment())
                                    @can('recordPayment', $invoice)
                                        <flux:button size="xs" wire:click="openPaymentForm({{ $invoice->id }})" inset="top bottom">
                                            {{ __('Record payment') }}
                                        </flux:button>
                                    @endcan
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="7" class="py-8 text-center">{{ __('No invoices have been issued to this agency.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <flux:modal name="record-payment" class="md:w-[28rem]">
                @if ($this->payingInvoice)
                    <form wire:submit="recordPayment" class="space-y-6">
                        <div>
                            <flux:heading size="lg">{{ __('Record a payment against :reference', ['reference' => $this->payingInvoice->reference]) }}</flux:heading>
                            <flux:text class="mt-2">
                                {{ __(':outstanding of :amount is still owed. Record money already received, such as a bank transfer; card payments are confirmed by the gateway.', [
                                    'outstanding' => Money::ofMinor($this->payingInvoice->outstandingMinor(), $this->payingInvoice->currency)->format(),
                                    'amount' => Money::ofMinor($this->payingInvoice->amount_minor, $this->payingInvoice->currency)->format(),
                                ]) }}
                            </flux:text>
                        </div>

                        <flux:field>
                            <flux:label>{{ __('Amount received') }}</flux:label>

                            <flux:input.group>
                                <flux:input.group.prefix>{{ $this->payingInvoice->currency }}</flux:input.group.prefix>

                                <flux:input wire:model="payment_amount" inputmode="decimal" required />
                            </flux:input.group>

                            <flux:error name="payment_amount" />
                        </flux:field>

                        <flux:select wire:model="payment_method" :label="__('Method')">
                            @foreach (RecordSubscriptionPaymentAction::manualMethods() as $paymentMethod)
                                <flux:select.option :value="$paymentMethod->value">{{ $paymentMethod->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input
                            type="date"
                            wire:model="payment_paid_on"
                            :label="__('Paid on')"
                            :min="$this->payingInvoice->issued_on->toDateString()"
                            :max="today()->toDateString()"
                            required
                        />

                        <div class="flex justify-end gap-2">
                            <flux:modal.close>
                                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                            </flux:modal.close>

                            <flux:button type="submit" variant="primary">{{ __('Record payment') }}</flux:button>
                        </div>
                    </form>
                @endif
            </flux:modal>
        </section>

        <section class="flex flex-col gap-4 lg:col-span-3">
            <flux:heading size="lg" level="2">{{ __('Payments') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Reference') }}</flux:table.column>
                    <flux:table.column class="max-md:hidden">{{ __('Invoice') }}</flux:table.column>
                    <flux:table.column class="max-sm:hidden">{{ __('Method') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                    <flux:table.column class="max-sm:hidden">{{ __('Paid on') }}</flux:table.column>
                    <flux:table.column class="max-lg:hidden">{{ __('Recorded by') }}</flux:table.column>
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
                            <flux:table.cell class="max-md:hidden">{{ $payment->invoice->reference }}</flux:table.cell>
                            <flux:table.cell class="max-sm:hidden">{{ $payment->method->label() }}</flux:table.cell>
                            <flux:table.cell align="end">{{ Money::ofMinor($payment->amount_minor, $payment->currency)->format() }}</flux:table.cell>
                            <flux:table.cell class="max-sm:hidden">{{ $payment->paid_on?->toFormattedDateString() ?? '—' }}</flux:table.cell>
                            <flux:table.cell class="max-lg:hidden">{{ $payment->method->isGatewaySettled() ? __('Gateway') : ($payment->recorder?->full_name ?? '—') }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="py-8 text-center">{{ __('This agency has not paid the platform anything yet.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </section>

        <section class="flex flex-col gap-4 lg:col-span-3">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <flux:heading size="lg" level="2">{{ __('Recent activity') }}</flux:heading>

                @can('viewAny', PlatformAuditEvent::class)
                    <flux:link :href="route('admin.audit.index', ['tenant' => $tenant->id])" variant="subtle" class="text-sm" wire:navigate>
                        {{ __('View the full audit log') }}
                    </flux:link>
                @endcan
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Action') }}</flux:table.column>
                    <flux:table.column class="max-sm:hidden">{{ __('By') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('When') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->recentActivity as $event)
                        <flux:table.row :key="$event->id">
                            <flux:table.cell variant="strong">{{ $event->action->label() }}</flux:table.cell>
                            <flux:table.cell class="max-sm:hidden">{{ $event->actor_label ?? __('System') }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <time datetime="{{ $event->created_on?->toIso8601String() }}" title="{{ $event->created_on?->toDayDateTimeString() }}">
                                    {{ $event->created_on?->diffForHumans() }}
                                </time>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="3" class="py-8 text-center">{{ __('No platform activity has been recorded for this agency.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </section>
    </div>
</div>
