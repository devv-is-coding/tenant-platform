<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\PlatformAuditEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditRecorder
{
    /**
     * Record an action where its subject lives, so the record survives and
     * dies with the data it describes: a subject in the agency's own database
     * goes to that agency's `audit_events`, and anything central (or no
     * subject at all) to `platform_audit_events`, tagged with the current
     * agency when there is one.
     *
     * Where the actor comes from does not decide it. Use this for agency
     * features.
     *
     * @param  array<string, mixed>|null  $changes
     * @param  array<string, mixed>|null  $context
     *
     * @throws LogicException
     */
    public function record(
        AuditAction $action,
        ?User $actor,
        ?Model $subject = null,
        ?string $description = null,
        ?array $changes = null,
        ?array $context = null,
    ): AuditEvent|PlatformAuditEvent {
        if ($subject !== null && ! $this->livesCentrally($subject)) {
            return $this->recordTenantEvent($action, $actor, $description, $changes, $subject, $context);
        }

        $tenant = tenant();

        return $this->recordPlatformEvent(
            $action,
            $actor,
            $tenant instanceof Tenant ? $tenant : null,
            $description,
            $changes,
            $subject,
            $context,
        );
    }

    /**
     * Record an agency action, such as an item approved or a setting changed,
     * in the current agency's own `audit_events` table.
     *
     * The actor is one of the agency's own users, or NULL for an
     * unauthenticated action - never 0. The platform never acts inside an
     * agency, so any other actor is refused, and a `tenant.*` lifecycle action
     * must go through recordTenantLifecycleNotice() instead.
     *
     * @param  array<string, mixed>|null  $changes
     * @param  array<string, mixed>|null  $context
     *
     * @throws LogicException
     */
    public function recordTenantEvent(
        AuditAction $action,
        ?User $actor,
        ?string $description = null,
        ?array $changes = null,
        ?Model $subject = null,
        ?array $context = null,
    ): AuditEvent {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw new LogicException("An agency action can only be recorded inside an agency, not [{$action->value}] outside one.");
        }

        if ($action->domain() === 'tenant') {
            throw new LogicException("[{$action->value}] is a lifecycle notice; record it with recordTenantLifecycleNotice().");
        }

        if ($actor !== null && $actor->tenant_id !== $tenant->id) {
            throw new LogicException("Only the agency's own users act inside it; user [{$actor->id}] does not belong to [{$tenant->id}].");
        }

        return AuditEvent::create([
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role,
            'actor_label' => $this->actorLabel($actor),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'changes' => $changes,
            'context' => $context,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Record an action whose subject is platform-level, such as a tenant being
     * created, in the central `platform_audit_events` table.
     *
     * The actor and tenant are snapshotted as labels, not just ids, so the row
     * still reads correctly after either is deleted. A NULL actor means the
     * action was unauthenticated - never 0, which is a valid role value.
     *
     * The subject is the record the action was about, such as a subscription,
     * stored under its morph alias. The context carries anything else worth
     * keeping, such as what a cascading delete took with it.
     *
     * @param  array<string, mixed>|null  $changes
     * @param  array<string, mixed>|null  $context
     */
    public function recordPlatformEvent(
        AuditAction $action,
        ?User $actor,
        ?Tenant $tenant = null,
        ?string $description = null,
        ?array $changes = null,
        ?Model $subject = null,
        ?array $context = null,
    ): PlatformAuditEvent {
        return PlatformAuditEvent::create([
            'tenant_id' => $tenant?->id,
            'tenant_label' => $tenant?->name,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role,
            'actor_label' => $this->actorLabel($actor),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'changes' => $changes,
            'context' => $context,
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
            // No subject_id: it is an integer column, and the subject is the
            // agency whose own log this is.
            'subject_type' => $tenant->getMorphClass(),
            'description' => $description,
            'changes' => $changes,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]));
    }

    /**
     * Determine whether the model is stored in the central database rather
     * than an agency's own.
     */
    private function livesCentrally(Model $model): bool
    {
        return $model->getConnectionName() === config('tenancy.database.central_connection');
    }

    /**
     * Snapshot who acted, so the row reads correctly after the user changes.
     */
    private function actorLabel(?User $actor): ?string
    {
        return $actor === null ? null : "{$actor->full_name} ({$actor->role->label()})";
    }
}
