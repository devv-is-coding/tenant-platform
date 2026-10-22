<?php

namespace App\Actions\Restore;

use App\Enums\AuditAction;
use App\Enums\RestoreRequestStatus;
use App\Models\RestoreRequest;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RequestRestoreAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * File an agency admin's request to have one of the agency's deleted
     * records brought back by a super admin.
     *
     * Authorized here rather than only by the page that calls it: the rule -
     * the agency's own admin, for the agency's own records - is the point of
     * the feature, so no caller can skip it.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(
        User $actor,
        Tenant $tenant,
        User|Subscription|SubscriptionInvoice|SubscriptionPayment $record,
        array $input,
    ): RestoreRequest {
        Gate::forUser($actor)->authorize('create', [RestoreRequest::class, $tenant]);

        $validated = Validator::make($input, ['reason' => ['required', 'string', 'max:1000']], [
            'reason.required' => __('Say why this should be restored.'),
        ])->validate();

        if ($record->tenant_id !== $tenant->id || ! $record->trashed()) {
            throw ValidationException::withMessages(['reason' => __('Only a deleted record of your own agency can be restored.')]);
        }

        $pending = RestoreRequest::query()
            ->where('restorable_type', $record->getMorphClass())
            ->where('restorable_id', $record->getKey())
            ->where('status', RestoreRequestStatus::Pending)
            ->exists();

        if ($pending) {
            throw ValidationException::withMessages(['reason' => __('A restore of this record has already been requested.')]);
        }

        $request = RestoreRequest::create([
            'tenant_id' => $tenant->id,
            'restorable_type' => $record->getMorphClass(),
            'restorable_id' => $record->getKey(),
            'requested_by' => $actor->id,
            'reason' => trim($validated['reason']),
            'status' => RestoreRequestStatus::Pending,
        ]);

        $this->auditRecorder->recordPlatformEvent(AuditAction::RestoreRequested, $actor, $tenant, $request->reason, subject: $request);

        return $request;
    }
}
