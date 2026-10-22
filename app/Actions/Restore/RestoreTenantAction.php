<?php

namespace App\Actions\Restore;

use App\Enums\AuditAction;
use App\Models\Domain;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class RestoreTenantAction
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private RestoreRecordAction $restoreRecord,
    ) {}

    /**
     * Bring back a deleted agency with exactly what its delete took: its
     * domains, so its address resolves again, its users, and its
     * subscriptions with their invoices and payments.
     *
     * Rows deleted before the agency was, on purpose, stay deleted. The
     * subscriptions stay Cancelled - the delete ended them - and the agency's
     * status is untouched. Like the delete, it is recorded centrally and as a
     * notice in the agency's own audit log, inside the transaction.
     */
    public function handle(User $actor, Tenant $tenant): void
    {
        if (! $tenant->trashed()) {
            throw ValidationException::withMessages(['restore' => __(':agency is not deleted.', ['agency' => $tenant->name ?? $tenant->id])]);
        }

        $tenant->getConnection()->transaction(function () use ($actor, $tenant): void {
            $locked = Tenant::query()->withTrashed()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $cascaded = $this->cascadeOf($locked);

            try {
                $locked->restore();

                $restored = [
                    'domain' => $this->restoreRecord->restoreTrashed(Domain::class, $cascaded['domain'] ?? []),
                    'user' => $this->restoreRecord->restoreTrashed(User::class, $cascaded['user'] ?? []),
                    'subscription' => $this->restoreRecord->restoreTrashed(Subscription::class, $cascaded['subscription'] ?? []),
                    'subscription_invoice' => $this->restoreRecord->restoreTrashed(SubscriptionInvoice::class, $cascaded['subscription_invoice'] ?? []),
                    'subscription_payment' => $this->restoreRecord->restoreTrashed(SubscriptionPayment::class, $cascaded['subscription_payment'] ?? []),
                ];
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['restore' => __(':agency cannot be restored: something now in use has its unique details.', ['agency' => $locked->name ?? $locked->id])]);
            }

            $this->auditRecorder->recordPlatformEvent(AuditAction::TenantRestored, $actor, $locked, context: ['restored' => $restored]);

            $this->auditRecorder->recordTenantLifecycleNotice(AuditAction::TenantRestored, $actor, $locked);
        });

        $tenant->refresh();
    }

    /**
     * Read which rows the agency's delete took with it.
     *
     * @return array<string, list<int>>
     */
    private function cascadeOf(Tenant $tenant): array
    {
        $event = PlatformAuditEvent::query()
            ->where('action', AuditAction::TenantDeleted)
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();

        $cascaded = $event?->context['cascaded'] ?? [];

        return is_array($cascaded) ? $cascaded : [];
    }
}
