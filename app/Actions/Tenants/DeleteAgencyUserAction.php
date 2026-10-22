<?php

namespace App\Actions\Tenants;

use App\Concerns\RequiresDeletionReason;
use App\Enums\AuditAction;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;

class DeleteAgencyUserAction
{
    use RequiresDeletionReason;

    public function __construct(
        private AuditRecorder $auditRecorder,
        private RevokeAgencyAdminAction $revokeAdmin,
    ) {}

    /**
     * Soft-delete one of an agency's user accounts.
     *
     * A deleted user can no longer be found by the auth provider, so any
     * session they hold stops working on its next request. The row, its email
     * address included, stays in the table: that address cannot sign up again
     * until the row is purged.
     *
     * Deleting the agency's only active admin is refused, as revoking them is:
     * the same last-admin guard applies however the account goes.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, Tenant $tenant, User $user, array $input): void
    {
        $reason = $this->validatedReason($input);

        $user->getConnection()->transaction(function () use ($actor, $tenant, $user, $reason): void {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $locked = $tenant->users()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $this->revokeAdmin->ensureNotLastActiveAdmin($tenant, $locked);

            $locked->delete();

            $this->auditRecorder->recordPlatformEvent(
                AuditAction::UserDeleted,
                $actor,
                $tenant,
                $reason,
                subject: $locked,
            );
        });
    }
}
