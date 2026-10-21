<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
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
 * @property Carbon|null $created_on
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
    use CentralConnection;

    const CREATED_AT = 'created_on';

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
     * Describe each recorded change as one line, e.g. "status: 2 → 4".
     *
     * Values are shown as stored: the log records what was written, so an
     * enum reads as its backing value rather than today's label for it.
     *
     * @return list<string>
     */
    public function changeSummary(): array
    {
        // Read through getAttribute(): inside the model, $this->changes is
        // Eloquent's own record of dirty attributes, not this column.
        $changes = $this->getAttribute('changes');

        if (! is_array($changes)) {
            return [];
        }

        $lines = [];

        foreach ($changes as $field => $change) {
            $lines[] = is_array($change) && array_key_exists('old', $change) && array_key_exists('new', $change)
                ? "{$field}: {$this->formatChangeValue($change['old'])} → {$this->formatChangeValue($change['new'])}"
                : "{$field}: {$this->formatChangeValue($change)}";
        }

        return $lines;
    }

    /**
     * Render one stored value for display.
     */
    private function formatChangeValue(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
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
