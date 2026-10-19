<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\PlatformAuditEvent;
use App\Models\Tenant;
use App\Models\User;

class AuditRecorder
{
    /**
     * Record an action whose subject is platform-level, such as a tenant being
     * created, in the central `platform_audit_events` table.
     *
     * The actor and tenant are snapshotted as labels, not just ids, so the row
     * still reads correctly after either is deleted. A NULL actor means the
     * action was unauthenticated - never 0, which is a valid role value.
     */
    public function recordPlatformEvent(AuditAction $action, ?User $actor, ?Tenant $tenant = null): PlatformAuditEvent
    {
        return PlatformAuditEvent::create([
            'tenant_id' => $tenant?->id,
            'tenant_label' => $tenant?->name,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role,
            'actor_label' => $actor === null ? null : "{$actor->full_name} ({$actor->role->label()})",
            'action' => $action,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
