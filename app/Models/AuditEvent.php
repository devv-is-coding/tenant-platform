<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An append-only record of something that happened inside one tenant, stored
 * in that tenant's own database. Write rows through AuditRecorder.
 *
 * No CentralConnection: the model uses whichever tenant database tenancy has
 * made the default, so it must only be touched inside tenant context.
 *
 * @property int $id
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
 * @property Carbon|null $created_on
 */
#[Fillable([
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
class AuditEvent extends Model
{
    use SoftDeletes;

    const CREATED_AT = 'created_on';

    const UPDATED_AT = null;

    const DELETED_AT = 'deleted_on';

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
        static::updating(fn (): never => throw new LogicException('Audit events are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Audit events are append-only.'));
    }
}
