<?php

namespace App\Actions\Tenants;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChangeTenantStatusAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Move an agency to a new lifecycle status: activate, pause, suspend,
     * close, or bring it back to Active.
     *
     * Every change is a deliberate act by a super admin, recorded twice: in the
     * central platform audit log, and as a notice in the agency's own audit log
     * so it keeps a durable record of why its access changed.
     *
     * A pause is the agency's own request, so it names the agency admin who
     * asked. A closure ends the agency's live subscription; a closed agency is
     * reopened only once it is on a plan again.
     *
     * The tenant row is locked and its status re-read inside the transaction,
     * so two super admins acting at once cannot both apply a change from the
     * same starting status. The tenant notice is written last and inside that
     * transaction: if it fails, the status change and the central row roll
     * back with it. No transaction spans both databases, so the reverse - the
     * central commit failing after the notice is written - remains possible,
     * and would leave a notice for a change that did not happen.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, Tenant $tenant, TenantStatus $status, array $input): Tenant
    {
        $validated = Validator::make($input, $this->rules($status, $tenant), $this->messages($status))->validate();
        $reason = blank($validated['status_reason'] ?? null) ? null : trim($validated['status_reason']);
        $requestedBy = $status === TenantStatus::Paused ? (int) $validated['requested_by'] : null;

        return $tenant->getConnection()->transaction(function () use ($actor, $tenant, $status, $reason, $requestedBy): Tenant {
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->status;

            if (! $previous->canTransitionTo($status)) {
                throw ValidationException::withMessages([
                    'status' => __('The agency is :from, so it cannot be made :to.', [
                        'from' => mb_strtolower($previous->label()),
                        'to' => mb_strtolower($status->label()),
                    ]),
                ]);
            }

            if ($previous === TenantStatus::Closed && ! $this->hasLiveSubscription($locked)) {
                throw ValidationException::withMessages([
                    'status' => __('Put the agency on a plan before reopening it.'),
                ]);
            }

            $locked->update([
                'status' => $status,
                'status_reason' => $reason,
                'status_changed_at' => now(),
                'status_changed_by' => $actor->id,
            ]);

            $context = array_filter([
                'requested_by' => $requestedBy,
                'cancelled_subscription_id' => $status === TenantStatus::Closed ? $this->endLiveSubscription($locked, $reason) : null,
            ]);

            $action = $this->auditActionFor($previous, $status);
            $changes = ['status' => ['old' => $previous->value, 'new' => $status->value]];

            $this->auditRecorder->recordPlatformEvent($action, $actor, $locked, $reason, $changes, context: $context === [] ? null : $context);
            $this->auditRecorder->recordTenantLifecycleNotice($action, $actor, $locked, $reason, $changes);

            return $locked;
        });
    }

    /**
     * Get the validation rules for moving the agency to the given status.
     * Pausing, suspending and closing must say why; a pause must also name
     * which of the agency's active admins asked for it.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(TenantStatus $status, Tenant $tenant): array
    {
        return [
            'status_reason' => [$status->requiresReason() ? 'required' : 'nullable', 'string', 'max:255'],
            'requested_by' => $status === TenantStatus::Paused
                ? ['required', 'integer', Rule::exists(User::class, 'id')
                    ->where('tenant_id', $tenant->id)
                    ->where('role', Role::Admin->value)
                    ->where('is_active', true)
                    ->withoutTrashed()]
                : ['nullable'],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(TenantStatus $status): array
    {
        return [
            'status_reason.required' => __('Say why the agency is being :status. The agency will see this reason.', ['status' => mb_strtolower($status->label())]),
            'requested_by.required' => __('Choose the agency admin who asked for the pause.'),
            'requested_by.exists' => __('Choose one of the agency\'s active admins.'),
        ];
    }

    /**
     * Cancel the agency's live subscription, if it has one, and return its id.
     * Its slot is freed, so a fresh subscription can be issued if the agency
     * ever returns.
     */
    private function endLiveSubscription(Tenant $tenant, ?string $reason): ?int
    {
        $live = Subscription::query()->where('active_for_tenant_id', $tenant->id)->lockForUpdate()->first();

        $live?->update([
            'active_for_tenant_id' => null,
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
            'next_billing_on' => null,
            'ends_on' => today(),
        ]);

        return $live?->id;
    }

    private function hasLiveSubscription(Tenant $tenant): bool
    {
        return Subscription::query()->where('active_for_tenant_id', $tenant->id)->exists();
    }

    /**
     * Name the change for the audit log by where it started as well as where
     * it ended: Pending to Active is a first activation, any other return to
     * Active a reactivation.
     */
    private function auditActionFor(TenantStatus $from, TenantStatus $to): AuditAction
    {
        return match (true) {
            $to === TenantStatus::Paused => AuditAction::TenantPaused,
            $to === TenantStatus::Suspended => AuditAction::TenantSuspended,
            $to === TenantStatus::Closed => AuditAction::TenantClosed,
            $from === TenantStatus::Pending => AuditAction::TenantActivated,
            default => AuditAction::TenantReactivated,
        };
    }
}
