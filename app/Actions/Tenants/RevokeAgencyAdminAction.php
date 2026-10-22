<?php

namespace App\Actions\Tenants;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RevokeAgencyAdminAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Revoke an agency admin's access: the account is deactivated and
     * soft-deleted, so it can no longer sign in.
     *
     * `tenant_id` is deliberately left set. The person remains, in the
     * record, the one who wrote everything they created or approved; nulling
     * it would leave an Admin belonging to no agency.
     *
     * The last-admin guard refuses to leave the agency with no active admin:
     * an agency that cannot govern itself would force the platform to make its
     * decisions. The tenant row is locked first, so two revocations at once
     * cannot each see the other admin as still there.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, Tenant $tenant, User $admin, array $input): void
    {
        $validated = Validator::make($input, ['revoke_reason' => ['required', 'string', 'max:255']], [
            'revoke_reason.required' => __('Say why this admin\'s access is revoked. The reason is kept in the audit log.'),
        ])->validate();
        $reason = trim($validated['revoke_reason']);

        $tenant->getConnection()->transaction(function () use ($actor, $tenant, $admin, $reason): void {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $locked = $tenant->users()->whereKey($admin->id)->where('role', Role::Admin)->lockForUpdate()->firstOrFail();

            $this->ensureNotLastActiveAdmin($tenant, $locked);

            $locked->update(['is_active' => false]);
            $locked->delete();

            $this->auditRecorder->recordPlatformEvent(
                AuditAction::TenantAdminRevoked,
                $actor,
                $tenant,
                $reason,
                subject: $locked,
            );
        });
    }

    /**
     * Refuse to take away the agency's only active admin, by revoking or by
     * deleting the account. Callers lock the tenant row first.
     *
     * @throws ValidationException
     */
    public function ensureNotLastActiveAdmin(Tenant $tenant, User $user): void
    {
        if ($user->role !== Role::Admin || ! $user->is_active) {
            return;
        }

        $otherActiveAdmins = $tenant->users()
            ->where('role', Role::Admin)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->count();

        if ($otherActiveAdmins === 0) {
            throw ValidationException::withMessages([
                'admin' => __(':name is the agency\'s only active admin. Assign another admin first.', ['name' => $user->full_name]),
            ]);
        }
    }
}
