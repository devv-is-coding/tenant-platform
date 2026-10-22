<?php

namespace App\Actions\Tenants;

use App\Enums\AuditAction;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ChangeTenantStatusAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Move an agency to a new lifecycle status: activate a pending agency,
     * suspend an active one, or reactivate a suspended one.
     *
     * Every change is a deliberate act by a super admin, recorded twice: in the
     * central platform audit log, and as a notice in the agency's own audit log
     * so it keeps a durable record of why its access changed.
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
        $validated = Validator::make($input, $this->rules($status), $this->messages())->validate();

        $reason = blank($validated['status_reason'] ?? null) ? null : trim($validated['status_reason']);

        return $tenant->getConnection()->transaction(function () use ($actor, $tenant, $status, $reason): Tenant {
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

            $locked->update([
                'status' => $status,
                'status_reason' => $reason,
                'status_changed_at' => now(),
                'status_changed_by' => $actor->id,
            ]);

            $action = $this->auditActionFor($previous, $status);
            $changes = ['status' => ['old' => $previous->value, 'new' => $status->value]];

            $this->auditRecorder->recordPlatformEvent($action, $actor, $locked, $reason, $changes);
            $this->auditRecorder->recordTenantLifecycleNotice($action, $actor, $locked, $reason, $changes);

            return $locked;
        });
    }

    /**
     * Get the validation rules for moving an agency to the given status. A
     * suspension must say why; activating or reactivating may.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(TenantStatus $status): array
    {
        return [
            'status_reason' => [$status === TenantStatus::Suspended ? 'required' : 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status_reason.required' => __('Say why the agency is being suspended. The agency will see this reason.'),
        ];
    }

    /**
     * Name the change for the audit log by where it started as well as where
     * it ended: Pending to Active is a first activation, Suspended to Active a
     * reactivation.
     */
    private function auditActionFor(TenantStatus $from, TenantStatus $to): AuditAction
    {
        return match (true) {
            $to === TenantStatus::Suspended => AuditAction::TenantSuspended,
            $from === TenantStatus::Pending => AuditAction::TenantActivated,
            default => AuditAction::TenantReactivated,
        };
    }
}
