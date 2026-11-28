<?php

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Services\Audit\AuditRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;
use Throwable;

/**
 * Sets up an agency's own database with stancl's own jobs, in order: create
 * it, migrate it, seed its settings row. Then a provisioning agency moves on
 * to Pending, where a super admin can activate it.
 *
 * Queued for an agency onboarded as Provisioning, once the onboarding
 * transaction has committed (TenancyServiceProvider::provisionDatabase()), so
 * the request never waits for migrations and a failed onboarding never leaves
 * a database behind. An agency created in any other status (seeders,
 * factories) is set up at once, as before, and its status is left alone.
 *
 * Tried three times. A later attempt, or the super admin's "Retry setup",
 * resumes: a database an earlier attempt created is migrated and seeded, not
 * created again. A first attempt still refuses a database that already
 * exists, since that one was left behind by something else.
 */
class ProvisionAgencyDatabase implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The seconds to wait before each retry.
     *
     * @var list<int>
     */
    public array $backoff = [10, 60];

    /**
     * An agency force-deleted before its turn has nothing left to set up.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(public Tenant $tenant, public bool $resuming = false) {}

    /**
     * Execute the job.
     */
    public function handle(DatabaseManager $databaseManager, AuditRecorder $auditRecorder): void
    {
        // stancl's CreateDatabase answers false when the agency opts out of
        // having a database, which ends the setup there, as its pipeline did.
        if (! $this->databaseWasCreatedEarlier() && (new CreateDatabase($this->tenant))->handle($databaseManager) === false) {
            return;
        }

        (new MigrateDatabase($this->tenant))->handle();
        (new SeedDatabase($this->tenant))->handle();

        $this->tenant->refresh();

        if ($this->tenant->status !== TenantStatus::Provisioning) {
            return;
        }

        $this->tenant->forceFill([
            'status' => TenantStatus::Pending,
            'status_reason' => null,
            'status_changed_at' => now(),
            'status_changed_by' => null,
        ])->save();

        $auditRecorder->recordPlatformEvent(AuditAction::TenantProvisioned, null, $this->tenant);
    }

    /**
     * Keep the agency, and say what went wrong where the super admin sees it:
     * on its page, which offers "Retry setup", and in the platform audit log.
     * An agency set up at once (any status but Provisioning) fails loudly
     * instead, in the code that created it.
     */
    public function failed(Throwable $exception): void
    {
        $tenant = $this->tenant->fresh();

        if (! $tenant instanceof Tenant || $tenant->status !== TenantStatus::Provisioning) {
            return;
        }

        $error = Str::limit($exception->getMessage() ?: $exception::class, 200);

        $tenant->forceFill(['status_reason' => __('Setup failed: :error', ['error' => $error])])->save();

        app(AuditRecorder::class)->recordPlatformEvent(AuditAction::TenantProvisioningFailed, null, $tenant, $error);
    }

    /**
     * Determine if this run resumes a setup whose database already exists.
     */
    private function databaseWasCreatedEarlier(): bool
    {
        if (! $this->resuming && $this->attempts() <= 1) {
            return false;
        }

        $database = $this->tenant->database();

        return $database->manager()->databaseExists($database->getName());
    }
}
