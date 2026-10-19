<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\PlatformAuditEvent;
use App\Models\Tenant;
use App\Models\User;
use LogicException;

class AuditRecorder
{
    /**
     * Record an action whose subject is platform-level, such as a tenant being
     * created, in the central `platform_audit_events` table.
     *
     * The actor and tenant are snapshotted as labels, not just ids, so the row
     * still reads correctly after either is deleted. A NULL actor means the
     * action was unauthenticated - never 0, which is a valid role value.
     *
     * @param  array<string, mixed>|null  $changes
     */
    public function recordPlatformEvent(
        AuditAction $action,
        ?User $actor,
        ?Tenant $tenant = null,
        ?string $description = null,
        ?array $changes = null,
    ): PlatformAuditEvent {
        return PlatformAuditEvent::create([
            'tenant_id' => $tenant?->id,
            'tenant_label' => $tenant?->name,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role,
            'actor_label' => $this->actorLabel($actor),
            'action' => $action,
            'description' => $description,
            'changes' => $changes,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Write a platform lifecycle notice, such as a suspension, into the
     * tenant's own `audit_events` table, so the agency keeps a permanent local
     * record of what the platform did to it and why.
     *
     * This is the only write the platform makes into a tenant database, and it
     * is limited to `tenant.*` actions: the platform decides whether an agency
     * operates, never how, so it has no operational action to record there.
     *
     * @param  array<string, mixed>|null  $changes
     */
    public function recordTenantLifecycleNotice(
        AuditAction $action,
        User $actor,
        Tenant $tenant,
        ?string $description = null,
        ?array $changes = null,
    ): AuditEvent {
        if ($action->domain() !== 'tenant') {
            throw new LogicException("The platform may only write tenant lifecycle notices into a tenant's audit log, not [{$action->value}].");
        }

        return $tenant->run(fn (): AuditEvent => AuditEvent::create([
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
            'actor_label' => $this->actorLabel($actor),
            'action' => $action,
            'subject_type' => 'tenant',
            'description' => $description,
            'changes' => $changes,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]));
    }

    /**
     * Snapshot who acted, so the row reads correctly after the user changes.
     */
    private function actorLabel(?User $actor): ?string
    {
        return $actor === null ? null : "{$actor->full_name} ({$actor->role->label()})";
    }
}
