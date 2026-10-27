<?php

namespace App\Concerns;

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The one path agency features record what they did: the signed-in user is
 * the actor, and the event goes into the agency's own log, so its Admin sees
 * everything the agency did in one place (TA-28).
 *
 * That holds for a central subject too, such as a team account: what an
 * agency does to its own team is the agency's record, not the platform's
 * (decided 2026-10-27). AuditRecorder::recordTenantEvent() refuses to record
 * outside an agency, or for an actor who is not one of its users.
 */
trait RecordsAuditEvents
{
    /**
     * Record an action taken by the signed-in user, or by nobody when no one
     * is signed in.
     *
     * @param  array<string, mixed>|null  $changes
     * @param  array<string, mixed>|null  $context
     */
    protected function recordAuditEvent(
        AuditAction $action,
        ?Model $subject = null,
        ?string $description = null,
        ?array $changes = null,
        ?array $context = null,
    ): AuditEvent {
        $actor = Auth::user();

        return app(AuditRecorder::class)->recordTenantEvent(
            $action,
            $actor instanceof User ? $actor : null,
            $description,
            $changes,
            $subject,
            $context,
        );
    }
}
