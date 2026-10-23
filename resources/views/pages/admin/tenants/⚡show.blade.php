<?php

use App\Actions\Restore\RestoreRecordAction;
use App\Actions\Restore\RestoreTenantAction;
use App\Actions\Subscriptions\DeleteSubscriptionAction;
use App\Actions\Subscriptions\DeleteSubscriptionInvoiceAction;
use App\Actions\Subscriptions\DeleteSubscriptionPaymentAction;
use App\Actions\Subscriptions\RecordSubscriptionPaymentAction;
use App\Actions\Subscriptions\StartSubscriptionAction;
use App\Actions\Tenants\AssignAgencyAdminAction;
use App\Actions\Tenants\ChangeTenantStatusAction;
use App\Actions\Tenants\DeleteAgencyUserAction;
use App\Actions\Tenants\DeleteTenantAction;
use App\Actions\Tenants\RevokeAgencyAdminAction;
use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PlanFeature;
use App\Enums\Role;
use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\TenantStatus;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\ValueObjects\Money;
use Carbon\CarbonInterface;
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

    /**
     * The agency admin who asked for a pause.
     */
    public ?int $requested_by = null;

    /**
     * Whether the admin form creates a new account or promotes someone
     * already at the agency.
     */
    public string $assign_mode = 'create';

    public string $first_name = '';

    public string $last_name = '';

    public string $username = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?int $user_id = null;

    /**
     * The admin the revoke confirmation is open for. Set only by
     * confirmRevoke(), never by the browser.
     */
    #[Locked]
    public ?int $revoking_id = null;

    public string $revoke_reason = '';

    public ?int $subscription_plan_id = null;

    /**
     * The invoice the payment form records against. Set only by
     * openPaymentForm(), never by the browser.
     */
    #[Locked]
    public ?int $paying_invoice_id = null;

    public string $payment_amount = '';

    public int $payment_method = PaymentMethod::BankTransfer->value;

    public string $payment_paid_at = '';

    /**
     * What the delete confirmation is open for: a user, subscription, invoice
     * or payment of this agency. Set only by confirmDelete(), never by the
     * browser.
     */
    #[Locked]
    public ?string $deleting_type = null;

    #[Locked]
    public ?int $deleting_id = null;

    public string $delete_reason = '';

    /**
     * What the restore confirmation is open for: one of this agency's deleted
     * users, subscriptions, invoices or payments. Set only by confirmRestore(),
     * never by the browser.
     */
    #[Locked]
    public ?string $restoring_type = null;

    #[Locked]
    public ?int $restoring_id = null;

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

        $this->tenant = $changeTenantStatus->handle(Auth::user(), $this->tenant, $target, $this->only('status_reason', 'requested_by'));

        $this->reset('status_reason', 'requested_by');
        unset($this->statusActions, $this->subscription, $this->subscriptions, $this->recentActivity);
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

        $subscription = $startSubscription->handle(Auth::user(), $this->tenant, $this->only('subscription_plan_id'));

        $this->reset('subscription_plan_id');
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
        $this->payment_paid_at = today()->toDateString();
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
                'paid_at' => $this->payment_paid_at,
            ]);
        } catch (ValidationException $exception) {
            // The action's fields share names with the plan form's, so its
            // errors are moved onto this form's fields.
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ["payment_{$field}" => $messages])
                ->all());
        }

        $this->reset('paying_invoice_id', 'payment_amount', 'payment_method', 'payment_paid_at');
        unset($this->subscription, $this->invoices, $this->payments, $this->balanceOwed, $this->recentActivity);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __(':amount recorded against :reference.', [
            'amount' => Money::ofMinor($payment->amount_minor, $payment->currency)->format(),
            'reference' => $invoice->reference,
        ]));
    }

    /**
     * Open the delete confirmation for one of this agency's records.
     */
    public function confirmDelete(string $type, int $id): void
    {
        $record = $this->recordToDelete($type, $id);
        $this->authorize('delete', $record);

        $this->resetErrorBag();
        $this->reset('delete_reason');
        $this->deleting_type = $type;
        $this->deleting_id = $id;
        unset($this->deletion);

        Flux::modal('delete-record')->show();
    }

    /**
     * Soft-delete the record the confirmation is open for, cascading to what
     * belongs to it.
     *
     * The record is looked up through this agency, so a tampered request
     * cannot reach another agency's rows. Authorized here because Livewire's
     * update requests do not re-run the admin route middleware.
     */
    public function deleteRecord(
        DeleteAgencyUserAction $deleteUser,
        DeleteSubscriptionAction $deleteSubscription,
        DeleteSubscriptionInvoiceAction $deleteInvoice,
        DeleteSubscriptionPaymentAction $deletePayment,
    ): void {
        $record = $this->recordToDelete((string) $this->deleting_type, (int) $this->deleting_id);
        $this->authorize('delete', $record);
        $input = $this->only('delete_reason');
        // Read before deleting: once trashed the record is no longer found.
        $label = $this->deletion['name'] ?? '';

        match (true) {
            $record instanceof User => $deleteUser->handle(Auth::user(), $this->tenant, $record, $input),
            $record instanceof Subscription => $deleteSubscription->handle(Auth::user(), $record, $input),
            $record instanceof SubscriptionInvoice => $deleteInvoice->handle(Auth::user(), $record, $input),
            $record instanceof SubscriptionPayment => $deletePayment->handle(Auth::user(), $record, $input),
        };

        $this->reset('deleting_type', 'deleting_id', 'delete_reason');
        unset($this->users, $this->subscription, $this->subscriptions, $this->invoices, $this->payments, $this->balanceOwed, $this->recentActivity, $this->deletion);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __(':name was deleted.', ['name' => $label]));
    }

    /**
     * Soft-delete the agency with its users, domains and billing records, and
     * return to the directory.
     */
    public function deleteAgency(DeleteTenantAction $deleteTenant): void
    {
        $this->authorize('delete', $this->tenant);

        $deleteTenant->handle(Auth::user(), $this->tenant, $this->only('delete_reason'));

        Flux::toast(variant: 'success', text: __(':name was deleted.', ['name' => $this->tenant->name ?? $this->tenant->id]));
        $this->redirectRoute('admin.tenants.index', navigate: true);
    }

    /**
     * Bring the deleted agency back, with what was deleted along with it.
     *
     * Authorized here because Livewire's update requests do not re-run the
     * console's route middleware.
     */
    public function restoreAgency(RestoreTenantAction $restoreTenant): void
    {
        $this->authorize('restore', $this->tenant);

        $restoreTenant->handle(Auth::user(), $this->tenant);

        unset($this->users, $this->subscription, $this->subscriptions, $this->invoices, $this->payments, $this->balanceOwed, $this->recentActivity, $this->deletedRecords, $this->agencyDeletion, $this->statusActions);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __(':name was restored.', ['name' => $this->tenant->name ?? $this->tenant->id]));
    }

    /**
     * Open the restore confirmation for one of this agency's deleted records.
     */
    public function confirmRestore(string $type, int $id): void
    {
        $record = $this->recordToRestore($type, $id);
        $this->authorize('restore', $record);

        $this->resetErrorBag();
        $this->restoring_type = $type;
        $this->restoring_id = $id;
        unset($this->restoration);

        Flux::modal('restore-record')->show();
    }

    /**
     * Restore the record the confirmation is open for, with what its delete
     * took along with it.
     *
     * The record is looked up through this agency, so a tampered request
     * cannot reach another agency's rows.
     */
    public function restoreRecord(RestoreRecordAction $restoreRecord): void
    {
        $record = $this->recordToRestore((string) $this->restoring_type, (int) $this->restoring_id);
        $this->authorize('restore', $record);

        $restoreRecord->handle(Auth::user(), $this->tenant, $record);

        $this->reset('restoring_type', 'restoring_id');
        unset($this->users, $this->subscription, $this->subscriptions, $this->invoices, $this->payments, $this->balanceOwed, $this->recentActivity, $this->deletedRecords, $this->restoration);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __(':name was restored.', ['name' => $restoreRecord->labelFor($record)]));
    }

    /**
     * Describe the open restore confirmation: what comes back, and what comes
     * back with it.
     *
     * @return array{name: string, cascade: string}|null
     */
    #[Computed]
    public function restoration(): ?array
    {
        if ($this->restoring_type === null || $this->restoring_id === null) {
            return null;
        }

        $record = $this->recordToRestore($this->restoring_type, $this->restoring_id);

        return [
            'name' => app(RestoreRecordAction::class)->labelFor($record),
            'cascade' => match (true) {
                $record instanceof User => __('They can sign in again.'),
                $record instanceof Subscription => __('Invoices and payments deleted with it come back too. It stays cancelled: putting the agency on a plan is a separate step.'),
                $record instanceof SubscriptionInvoice => __('Payments deleted with it come back too, and its paid amount is recalculated.'),
                $record instanceof SubscriptionPayment => __('Its invoice\'s paid amount is recalculated with it.'),
            },
        ];
    }

    /**
     * Get the agency's deleted users, subscriptions, invoices and payments,
     * most recently deleted first. Empty while the agency itself is deleted:
     * restoring the agency brings back what was deleted with it.
     *
     * @return list<array{type: string, id: int, kind: string, name: string, deleted_at: CarbonInterface|null}>
     */
    #[Computed]
    public function deletedRecords(): array
    {
        if ($this->tenant->trashed()) {
            return [];
        }

        $restoreRecord = app(RestoreRecordAction::class);
        $describe = fn (string $type, string $kind) => fn (User|Subscription|SubscriptionInvoice|SubscriptionPayment $record): array => [
            'type' => $type,
            'id' => $record->id,
            'kind' => $kind,
            'name' => $restoreRecord->labelFor($record),
            'deleted_at' => $record->deleted_at,
        ];

        return collect()
            ->concat($this->tenant->users()->onlyTrashed()->get()->map($describe('user', __('User'))))
            ->concat($this->tenant->subscriptions()->onlyTrashed()->get()->map($describe('subscription', __('Subscription'))))
            ->concat($this->tenant->subscriptionInvoices()->onlyTrashed()->get()->map($describe('invoice', __('Invoice'))))
            ->concat($this->tenant->subscriptionPayments()->onlyTrashed()->get()->map($describe('payment', __('Payment'))))
            ->sortByDesc(fn (array $record): int => $record['deleted_at']?->getTimestamp() ?? 0)
            ->values()
            ->all();
    }

    /**
     * Get when and why the agency was deleted, while it is.
     *
     * @return array{deleted_at: CarbonInterface|null, reason: string|null}|null
     */
    #[Computed]
    public function agencyDeletion(): ?array
    {
        if (! $this->tenant->trashed()) {
            return null;
        }

        return [
            'deleted_at' => $this->tenant->deleted_at,
            'reason' => $this->tenant->platformAuditEvents()->where('action', AuditAction::TenantDeleted)->latest('id')->value('description'),
        ];
    }

    /**
     * Find one of this agency's deleted records by type, or fail with a 404.
     */
    private function recordToRestore(string $type, int $id): User|Subscription|SubscriptionInvoice|SubscriptionPayment
    {
        return match ($type) {
            'user' => $this->tenant->users()->onlyTrashed()->findOrFail($id),
            'subscription' => $this->tenant->subscriptions()->onlyTrashed()->findOrFail($id),
            'invoice' => $this->tenant->subscriptionInvoices()->onlyTrashed()->findOrFail($id),
            'payment' => $this->tenant->subscriptionPayments()->onlyTrashed()->findOrFail($id),
            default => abort(404),
        };
    }

    /**
     * Describe what the open delete confirmation will remove, including what
     * cascades with it.
     *
     * @return array{name: string, cascade: string|null}|null
     */
    #[Computed]
    public function deletion(): ?array
    {
        if ($this->deleting_type === null || $this->deleting_id === null) {
            return null;
        }

        $record = $this->recordToDelete($this->deleting_type, $this->deleting_id);

        return match (true) {
            $record instanceof User => ['name' => $record->full_name, 'cascade' => null],
            $record instanceof Subscription => ['name' => $record->plan_name, 'cascade' => __('Its invoices and their payments are deleted with it.')],
            $record instanceof SubscriptionInvoice => ['name' => $record->reference, 'cascade' => __('Its payments are deleted with it.')],
            $record instanceof SubscriptionPayment => ['name' => $record->reference, 'cascade' => __('The invoice\'s paid amount is recalculated without it.')],
        };
    }

    /**
     * Find one of this agency's records by type, or fail with a 404.
     */
    private function recordToDelete(string $type, int $id): User|Subscription|SubscriptionInvoice|SubscriptionPayment
    {
        return match ($type) {
            'user' => $this->tenant->users()->findOrFail($id),
            'subscription' => $this->tenant->subscriptions()->findOrFail($id),
            'invoice' => $this->tenant->subscriptionInvoices()->findOrFail($id),
            'payment' => $this->tenant->subscriptionPayments()->findOrFail($id),
            default => abort(404),
        };
    }

    /**
     * Get the status changes open to the agency from where it stands now,
     * with the wording for each confirmation.
     *
     * @return list<array{status: TenantStatus, label: string, heading: string, description: string, reasonRequired: bool, needsRequester: bool, variant: string}>
     */
    #[Computed]
    public function statusActions(): array
    {
        if ($this->tenant->trashed()) {
            return [];
        }

        $name = $this->tenant->name ?? $this->tenant->id;
        $current = $this->tenant->status;

        return collect(TenantStatus::cases())
            ->filter(fn (TenantStatus $status): bool => $current->canTransitionTo($status))
            ->map(fn (TenantStatus $status): array => [
                'status' => $status,
                'reasonRequired' => $status->requiresReason(),
                'needsRequester' => $status === TenantStatus::Paused,
                ...match (true) {
                    $status === TenantStatus::Paused => [
                        'label' => __('Pause'),
                        'heading' => __('Pause :name?', ['name' => $name]),
                        'description' => __('At the agency\'s own request, such as a seasonal hiatus. Its staff keep read-only access. The reason is written into the agency\'s own audit log.'),
                        'variant' => 'filled',
                    ],
                    $status === TenantStatus::Suspended => [
                        'label' => __('Suspend'),
                        'heading' => __('Suspend :name?', ['name' => $name]),
                        'description' => __('Its staff keep read-only access, so they can still honour travel already sold. The reason is written into the agency\'s own audit log.'),
                        'variant' => 'danger',
                    ],
                    $status === TenantStatus::Closed => [
                        'label' => __('Close'),
                        'heading' => __('Close :name?', ['name' => $name]),
                        'description' => __('It stops trading and its live subscription is cancelled. Its staff keep read-only access, with customers\' details and money figures hidden until it is on a plan again. Nothing is deleted.'),
                        'variant' => 'danger',
                    ],
                    $current === TenantStatus::Pending => [
                        'label' => __('Activate'),
                        'heading' => __('Activate :name?', ['name' => $name]),
                        'description' => __('The agency goes live and its staff can sign in with full access.'),
                        'variant' => 'primary',
                    ],
                    $current === TenantStatus::Closed => [
                        'label' => __('Reopen'),
                        'heading' => __('Reopen :name?', ['name' => $name]),
                        'description' => __('Only once it is on a plan again: start its subscription first. Its staff get full access back.'),
                        'variant' => 'primary',
                    ],
                    default => [
                        'label' => __('Reactivate'),
                        'heading' => __('Reactivate :name?', ['name' => $name]),
                        'description' => __('Its staff get full access back. Payment alone never does this; it is your decision.'),
                        'variant' => 'primary',
                    ],
                },
            ])
            ->values()
            ->all();
    }

    /**
     * Get the agency's active admins, one of whom must have asked for a pause.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function activeAdmins(): Collection
    {
        return $this->tenant->users()->where('role', Role::Admin)->where('is_active', true)->orderBy('first_name')->orderBy('last_name')->get();
    }

    /**
     * Get the agency's active managers and staff, who can be promoted to admin.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function promotableUsers(): Collection
    {
        return $this->tenant->users()
            ->whereIn('role', [Role::Manager, Role::Staff])
            ->where('is_active', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

    /**
     * Open the form for giving the agency another admin.
     */
    public function openAssignAdmin(): void
    {
        $this->authorize('manageAdmins', $this->tenant);

        $this->resetErrorBag();
        $this->reset('assign_mode', 'first_name', 'last_name', 'username', 'email', 'password', 'password_confirmation', 'user_id');

        Flux::modal('assign-admin')->show();
    }

    /**
     * Give the agency an admin, by creating their account or promoting one of
     * its managers or staff.
     *
     * Authorized here because Livewire's update requests do not re-run the
     * console's route middleware.
     */
    public function assignAdmin(AssignAgencyAdminAction $assignAdmin): void
    {
        $this->authorize('manageAdmins', $this->tenant);

        $admin = $this->assign_mode === 'promote'
            ? $assignAdmin->promote(Auth::user(), $this->tenant, $this->only('user_id'))
            : $assignAdmin->create(Auth::user(), $this->tenant, $this->only(['first_name', 'last_name', 'username', 'email', 'password', 'password_confirmation']));

        $this->reset('assign_mode', 'first_name', 'last_name', 'username', 'email', 'password', 'password_confirmation', 'user_id');
        unset($this->users, $this->activeAdmins, $this->promotableUsers, $this->recentActivity);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __(':name is now an admin of :agency.', ['name' => $admin->full_name, 'agency' => $this->tenant->name ?? $this->tenant->id]));
    }

    /**
     * Open the revoke confirmation for one of the agency's admins.
     */
    public function confirmRevoke(int $id): void
    {
        $this->authorize('manageAdmins', $this->tenant);
        $admin = $this->tenant->users()->where('role', Role::Admin)->findOrFail($id);

        $this->resetErrorBag();
        $this->reset('revoke_reason');
        $this->revoking_id = $admin->id;
        unset($this->revoking);

        Flux::modal('revoke-admin')->show();
    }

    /**
     * Revoke the admin the confirmation is open for.
     */
    public function revokeAdmin(RevokeAgencyAdminAction $revokeAdmin): void
    {
        $this->authorize('manageAdmins', $this->tenant);
        $admin = $this->tenant->users()->where('role', Role::Admin)->findOrFail($this->revoking_id);

        $revokeAdmin->handle(Auth::user(), $this->tenant, $admin, $this->only('revoke_reason'));

        $this->reset('revoking_id', 'revoke_reason');
        unset($this->users, $this->activeAdmins, $this->deletedRecords, $this->recentActivity, $this->revoking);
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __(':name\'s admin access was revoked.', ['name' => $admin->full_name]));
    }

    /**
     * Get the admin the revoke confirmation is open for.
     */
    #[Computed]
    public function revoking(): ?User
    {
        return $this->revoking_id === null ? null : $this->tenant->users()->find($this->revoking_id);
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
     * Get every feature the agency's tier includes now.
     *
     * The live subscription is reloaded rather than read from the model, since
     * an action earlier in this request may have just changed it.
     *
     * @return list<PlanFeature>
     */
    #[Computed]
    public function features(): array
    {
        return $this->tenant->load('liveSubscription')->features();
    }

    /**
     * Get the plans in the price list the agency can be put on, by tier.
     *
     * @return Collection<int, SubscriptionPlan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return SubscriptionPlan::query()->orderBy('tier')->orderBy('name')->get();
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
            ->latest('paid_at')
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
            ->latest('created_at')
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

            <div class="flex flex-wrap gap-2">
                @foreach ($this->statusActions as $action)
                    <flux:modal.trigger :name="'change-status-'.$action['status']->value">
                        <flux:button :variant="$action['variant']">{{ $action['label'] }}</flux:button>
                    </flux:modal.trigger>
                @endforeach

                @can('delete', $tenant)
                    <flux:modal.trigger name="delete-agency">
                        <flux:button variant="ghost" icon="trash" class="text-red-600! dark:text-red-400!">{{ __('Delete agency') }}</flux:button>
                    </flux:modal.trigger>
                @endcan
            </div>
        </div>

        @can('delete', $tenant)
            <flux:modal name="delete-agency" class="md:w-[28rem]">
                <form wire:submit="deleteAgency" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Delete :name?', ['name' => $tenant->name ?? $tenant->id]) }}</flux:heading>
                        <flux:text class="mt-2">
                            {{ __('Its users, domains, subscriptions, invoices and payments are deleted with it, and its address stops working. Nothing is destroyed: every row is kept with a deletion date, and so is the agency\'s own database and audit trail.') }}
                        </flux:text>
                    </div>
    
                    <flux:textarea wire:model="delete_reason" :label="__('Reason')" rows="3" required />
    
                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
    
                        <flux:button type="submit" variant="danger">{{ __('Delete agency') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endcan

        <flux:modal name="delete-record" class="md:w-[28rem]">
            @if ($this->deletion)
                <form wire:submit="deleteRecord" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Delete :name?', ['name' => $this->deletion['name']]) }}</flux:heading>
                        <flux:text class="mt-2">
                            {{ $this->deletion['cascade'] }}
                            {{ __('Nothing is destroyed: the row is kept with a deletion date.') }}
                        </flux:text>
                    </div>

                    <flux:textarea wire:model="delete_reason" :label="__('Reason')" rows="3" required />

                    <flux:error name="admin" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="danger">{{ __('Delete') }}</flux:button>
                    </div>
                </form>
            @endif
        </flux:modal>

        <flux:text class="mt-2">
            {{ $tenant->domains->pluck('domain')->join(', ') ?: $tenant->id }}
            &middot;
            {{ __('Onboarded :date', ['date' => $tenant->created_at?->toFormattedDateString()]) }}
        </flux:text>

        @if ($this->agencyDeletion)
            <flux:callout variant="danger" icon="trash" class="mt-4" :heading="__('Deleted :date', ['date' => $this->agencyDeletion['deleted_at']?->toFormattedDateString()])">
                @if ($this->agencyDeletion['reason'])
                    <flux:callout.text>{{ $this->agencyDeletion['reason'] }}</flux:callout.text>
                @endif

                @can('restore', $tenant)
                    <x-slot name="actions">
                        <flux:modal.trigger name="restore-agency">
                            <flux:button size="sm">{{ __('Restore agency') }}</flux:button>
                        </flux:modal.trigger>
                    </x-slot>
                @endcan
            </flux:callout>

            <flux:modal name="restore-agency" class="md:w-[28rem]">
                <form wire:submit="restoreAgency" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Restore :name?', ['name' => $tenant->name ?? $tenant->id]) }}</flux:heading>
                        <flux:text class="mt-2">
                            {{ __('Its domains, users, subscriptions, invoices and payments deleted with it come back, and its address works again. Anything deleted before the agency stays deleted. Its subscriptions stay cancelled.') }}
                        </flux:text>
                    </div>

                    <flux:error name="restore" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary">{{ __('Restore agency') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @elseif ($tenant->status_reason)
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

                    @if ($action['needsRequester'])
                        <flux:select wire:model="requested_by" :label="__('Requested by')" :placeholder="__('Choose the agency admin who asked…')" required>
                            @foreach ($this->activeAdmins as $admin)
                                <flux:select.option :value="$admin->id" wire:key="requester-{{ $admin->id }}">{{ $admin->full_name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

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
            <div class="flex items-center justify-between gap-3">
                <flux:heading size="lg" level="2">{{ __('Users') }}</flux:heading>

                @can('manageAdmins', $tenant)
                    <flux:button size="sm" icon="user-plus" wire:click="openAssignAdmin">{{ __('Assign admin') }}</flux:button>
                @endcan
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Role') }}</flux:table.column>
                    <flux:table.column class="max-sm:hidden">{{ __('Account') }}</flux:table.column>
                    <flux:table.column align="end"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
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

                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-1">
                                    @if ($user->role === Role::Admin && $user->is_active)
                                        @can('manageAdmins', $tenant)
                                            <flux:button size="xs" variant="ghost" icon="user-minus" inset="top bottom" wire:click="confirmRevoke({{ $user->id }})" :aria-label="__('Revoke :name\'s admin access', ['name' => $user->full_name])" />
                                        @endcan
                                    @endif

                                    @can('delete', $user)
                                        <flux:button size="xs" variant="ghost" icon="trash" inset="top bottom" wire:click="confirmDelete('user', {{ $user->id }})" :aria-label="__('Delete :name', ['name' => $user->full_name])" />
                                    @endcan
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="py-8 text-center">{{ __('This agency has no users.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <flux:modal name="assign-admin" class="md:w-[32rem]">
                <form wire:submit="assignAdmin" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Assign an admin to :name', ['name' => $tenant->name ?? $tenant->id]) }}</flux:heading>
                        <flux:text class="mt-2">{{ __('Admins govern the agency: its settings, its people and its approvals.') }}</flux:text>
                    </div>

                    <flux:radio.group wire:model.live="assign_mode" :label="__('Who becomes admin')" variant="segmented">
                        <flux:radio value="create" :label="__('A new account')" />
                        <flux:radio value="promote" :label="__('Someone already here')" />
                    </flux:radio.group>

                    @if ($assign_mode === 'promote')
                        @if ($this->promotableUsers->isEmpty())
                            <flux:text>{{ __('The agency has no active managers or staff to promote. Create a new account instead.') }}</flux:text>
                        @else
                            <flux:select wire:model="user_id" :label="__('Person')" :placeholder="__('Choose a manager or staff member…')" required>
                                @foreach ($this->promotableUsers as $promotable)
                                    <flux:select.option :value="$promotable->id" wire:key="promotable-{{ $promotable->id }}">
                                        {{ $promotable->full_name }} · {{ $promotable->role->label() }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif
                    @else
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:input wire:model="first_name" :label="__('First name')" required />
                            <flux:input wire:model="last_name" :label="__('Last name')" required />
                        </div>

                        <flux:input wire:model="username" :label="__('Username')" required />
                        <flux:input wire:model="email" type="email" :label="__('Email')" required />
                        <flux:input wire:model="password" type="password" :label="__('Password')" viewable required />
                        <flux:input wire:model="password_confirmation" type="password" :label="__('Confirm password')" viewable required />
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary">{{ __('Assign admin') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal name="revoke-admin" class="md:w-[28rem]">
                @if ($this->revoking)
                    <form wire:submit="revokeAdmin" class="space-y-6">
                        <div>
                            <flux:heading size="lg">{{ __('Revoke :name\'s admin access?', ['name' => $this->revoking->full_name]) }}</flux:heading>
                            <flux:text class="mt-2">{{ __('Their account is deactivated and they can no longer sign in. They stay on record as the person who did everything they did here.') }}</flux:text>
                        </div>

                        <flux:textarea wire:model="revoke_reason" :label="__('Reason')" rows="3" required />

                        <flux:error name="admin" />

                        <div class="flex justify-end gap-2">
                            <flux:modal.close>
                                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                            </flux:modal.close>

                            <flux:button type="submit" variant="danger">{{ __('Revoke access') }}</flux:button>
                        </div>
                    </form>
                @endif
            </flux:modal>
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
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading>{{ $this->subscription->plan_name }}</flux:heading>
                            <flux:badge :color="$this->subscription->tier->color()" size="sm">{{ $this->subscription->tier->label() }}</flux:badge>
                        </div>
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

                    <div>
                        <flux:text size="sm" class="mb-2">{{ __('Features the agency has now') }}</flux:text>

                        @if ($this->features === [])
                            <flux:text>{{ __('None: the agency has no live plan.') }}</flux:text>
                        @else
                            <ul class="flex flex-wrap gap-1.5">
                                @foreach ($this->features as $feature)
                                    <li wire:key="feature-{{ $feature->value }}"><flux:badge size="sm" color="zinc">{{ $feature->label() }}</flux:badge></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
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
                                {{ __('The plan starts today. Its name, tier, price and billing interval are recorded as agreed.') }}
                            @endif
                        </flux:text>
                    </div>

                    @if ($this->plans->isEmpty())
                        <flux:callout icon="information-circle" :heading="__('The price list is empty')">
                            <flux:callout.text>
                                {{ __('Add a plan to the price list before putting an agency on one.') }}
                                <flux:callout.link :href="route('admin.plans.index')" wire:navigate>{{ __('Open plans') }}</flux:callout.link>
                            </flux:callout.text>
                        </flux:callout>
                    @else
                        <flux:select wire:model="subscription_plan_id" :label="__('Plan')" :placeholder="__('Choose a plan…')" required>
                            @foreach ($this->plans as $plan)
                                <flux:select.option :value="$plan->id" wire:key="plan-{{ $plan->id }}">
                                    {{ $plan->tier->label() }} · {{ $plan->name }} · {{ Money::ofMinor($plan->amount_minor, $plan->currency)->format() }} · {{ $plan->interval->label() }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary" :disabled="$this->plans->isEmpty()">
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
                    <flux:table.column align="end"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->subscriptions as $pastSubscription)
                        <flux:table.row :key="$pastSubscription->id">
                            <flux:table.cell variant="strong">
                                {{ $pastSubscription->plan_name }}
                                <flux:badge :color="$pastSubscription->tier->color()" size="sm" inset="top bottom" class="ms-1">{{ $pastSubscription->tier->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ Money::ofMinor($pastSubscription->amount_minor, $pastSubscription->currency)->format() }}
                                <flux:text size="sm">{{ $pastSubscription->interval->label() }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="max-sm:hidden">
                                <flux:badge :color="$pastSubscription->status->color()" size="sm" inset="top bottom">{{ $pastSubscription->status->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="max-sm:hidden">{{ $pastSubscription->starts_on->toFormattedDateString() }}</flux:table.cell>
                            <flux:table.cell class="max-sm:hidden">{{ $pastSubscription->ends_on?->toFormattedDateString() ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="end">
                                @can('delete', $pastSubscription)
                                    <flux:button size="xs" variant="ghost" icon="trash" inset="top bottom" wire:click="confirmDelete('subscription', {{ $pastSubscription->id }})" :aria-label="__('Delete :name', ['name' => $pastSubscription->plan_name])" />
                                @endcan
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="py-8 text-center">{{ __('No subscriptions yet.') }}</flux:table.cell>
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
                                <div class="flex justify-end gap-1">
                                    @if ($invoice->status->acceptsPayment())
                                        @can('recordPayment', $invoice)
                                            <flux:button size="xs" wire:click="openPaymentForm({{ $invoice->id }})" inset="top bottom">
                                                {{ __('Record payment') }}
                                            </flux:button>
                                        @endcan
                                    @endif

                                    @can('delete', $invoice)
                                        <flux:button size="xs" variant="ghost" icon="trash" inset="top bottom" wire:click="confirmDelete('invoice', {{ $invoice->id }})" :aria-label="__('Delete :name', ['name' => $invoice->reference])" />
                                    @endcan
                                </div>
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
                            wire:model="payment_paid_at"
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
                    <flux:table.column align="end"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
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
                            <flux:table.cell class="max-sm:hidden">{{ $payment->paid_at?->toFormattedDateString() ?? '—' }}</flux:table.cell>
                            <flux:table.cell class="max-lg:hidden">{{ $payment->method->isGatewaySettled() ? __('Gateway') : ($payment->recorder?->full_name ?? '—') }}</flux:table.cell>
                            <flux:table.cell align="end">
                                @can('delete', $payment)
                                    <flux:button size="xs" variant="ghost" icon="trash" inset="top bottom" wire:click="confirmDelete('payment', {{ $payment->id }})" :aria-label="__('Delete :name', ['name' => $payment->reference])" />
                                @endcan
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="7" class="py-8 text-center">{{ __('This agency has not paid the platform anything yet.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </section>

        @if ($this->deletedRecords !== [])
            <section class="flex flex-col gap-4 lg:col-span-3">
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
                        @foreach ($this->deletedRecords as $deleted)
                            <flux:table.row :key="$deleted['type'].'-'.$deleted['id']">
                                <flux:table.cell variant="strong">
                                    {{ $deleted['name'] }}
                                    <flux:text size="sm" class="sm:hidden">{{ $deleted['kind'] }}</flux:text>
                                </flux:table.cell>

                                <flux:table.cell class="max-sm:hidden">{{ $deleted['kind'] }}</flux:table.cell>

                                <flux:table.cell>{{ $deleted['deleted_at']?->toFormattedDateString() }}</flux:table.cell>

                                <flux:table.cell align="end">
                                    <flux:button size="xs" variant="ghost" icon="arrow-uturn-left" inset="top bottom" wire:click="confirmRestore('{{ $deleted['type'] }}', {{ $deleted['id'] }})">
                                        {{ __('Restore') }}
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </section>
        @endif

        <flux:modal name="restore-record" class="md:w-[28rem]">
            @if ($this->restoration)
                <form wire:submit="restoreRecord" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Restore :name?', ['name' => $this->restoration['name']]) }}</flux:heading>
                        <flux:text class="mt-2">{{ $this->restoration['cascade'] }}</flux:text>
                    </div>

                    <flux:error name="restore" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary">{{ __('Restore') }}</flux:button>
                    </div>
                </form>
            @endif
        </flux:modal>

        <section class="flex flex-col gap-4 lg:col-span-3">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <flux:heading size="lg" level="2">{{ __('Recent activity') }}</flux:heading>

                @can('viewAny', \App\Models\PlatformAuditEvent::class)
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
                                <time datetime="{{ $event->created_at?->toIso8601String() }}" title="{{ $event->created_at?->toDayDateTimeString() }}">
                                    {{ $event->created_at?->diffForHumans() }}
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
