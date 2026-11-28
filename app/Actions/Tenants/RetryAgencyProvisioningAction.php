<?php

namespace App\Actions\Tenants;

use App\Enums\AuditAction;
use App\Jobs\ProvisionAgencyDatabase;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RetryAgencyProvisioningAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Run a failed agency setup again, from where it stopped: a database the
     * failed run created is migrated and seeded, not created again
     * (ProvisionAgencyDatabase).
     *
     * Only a failed setup can be retried: one still running has no error yet
     * (status_reason), and a second run beside it would race it. The row is
     * locked while the error is cleared, so two clicks queue one run. The job
     * is queued after the commit, so it never sees the error still set.
     *
     * Authorizes itself (TenantPolicy::retryProvisioning).
     */
    public function handle(User $actor, Tenant $tenant): void
    {
        Gate::forUser($actor)->authorize('retryProvisioning', $tenant);

        $tenant->getConnection()->transaction(function () use ($actor, $tenant): void {
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isProvisioned() || $locked->status_reason === null) {
                throw ValidationException::withMessages([
                    'provisioning' => __(':agency is not waiting on a failed setup.', ['agency' => $locked->name ?? $locked->id]),
                ]);
            }

            $error = $locked->status_reason;
            $locked->forceFill(['status_reason' => null])->save();

            $this->auditRecorder->recordPlatformEvent(AuditAction::TenantProvisioningRetried, $actor, $locked, $error);

            ProvisionAgencyDatabase::dispatch($locked, resuming: true)->afterCommit();
        });

        $tenant->refresh();
    }
}
