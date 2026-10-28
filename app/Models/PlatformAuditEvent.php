<?php

namespace App\Models;

use App\Concerns\SummarizesAuditChanges;
use App\Enums\AuditAction;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * An append-only record of a platform-level action. Write rows through
 * AuditRecorder, which snapshots the actor and request.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string|null $tenant_label
 * @property int|null $actor_id
 * @property Role|null $actor_role
 * @property string|null $actor_label
 * @property AuditAction $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $description
 * @property array<string, mixed>|null $changes
 * @property array<string, mixed>|null $context
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'tenant_id',
    'tenant_label',
    'actor_id',
    'actor_role',
    'actor_label',
    'action',
    'subject_type',
    'subject_id',
    'description',
    'changes',
    'context',
    'ip_address',
    'user_agent',
])]
class PlatformAuditEvent extends Model
{
    /**
     * The table lives in the central database, so the model must stay on the
     * central connection even when written from inside tenant context.
     */
    use CentralConnection, SoftDeletes, SummarizesAuditChanges;

    const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_role' => Role::class,
            'action' => AuditAction::class,
            'changes' => 'array',
            'context' => 'array',
        ];
    }

    /**
     * An audit trail that can be edited is not evidence, so updates and deletes
     * through the model are refused outright.
     */
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Platform audit events are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Platform audit events are append-only.'));
    }
}
