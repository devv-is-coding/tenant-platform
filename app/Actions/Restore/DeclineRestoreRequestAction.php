<?php

namespace App\Actions\Restore;

use App\Enums\AuditAction;
use App\Enums\RestoreRequestStatus;
use App\Models\RestoreRequest;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DeclineRestoreRequestAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Decline an agency admin's restore request, with a reason the admin can
     * be told. The record stays deleted.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, RestoreRequest $request, array $input): void
    {
        $validated = Validator::make($input, ['decision_reason' => ['required', 'string', 'max:1000']], [
            'decision_reason.required' => __('Say why the request is declined.'),
        ])->validate();

        $request->getConnection()->transaction(function () use ($actor, $request, $validated): void {
            $locked = RestoreRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RestoreRequestStatus::Pending) {
                throw ValidationException::withMessages(['decision_reason' => __('This request has already been decided.')]);
            }

            $locked->update([
                'status' => RestoreRequestStatus::Declined,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_reason' => trim($validated['decision_reason']),
            ]);

            $this->auditRecorder->recordPlatformEvent(
                AuditAction::RestoreDeclined,
                $actor,
                $locked->tenant,
                $locked->decision_reason,
                subject: $locked,
            );
        });

        $request->refresh();
    }
}
