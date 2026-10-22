<?php

namespace App\Models;

use App\Enums\RestoreRequestStatus;
use Database\Factories\RestoreRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * An agency admin's request to bring back one of the agency's deleted records.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $restorable_type
 * @property int $restorable_id
 * @property int|null $requested_by
 * @property string $reason
 * @property RestoreRequestStatus $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant|null $tenant
 * @property-read User|null $requester
 * @property-read User|Subscription|SubscriptionInvoice|SubscriptionPayment|null $restorable
 */
#[Fillable([
    'tenant_id',
    'restorable_type',
    'restorable_id',
    'requested_by',
    'reason',
    'status',
    'decided_by',
    'decided_at',
    'decision_reason',
])]
class RestoreRequest extends Model
{
    /**
     * The table lives in the central database, so the model must stay on the
     * central connection even when read from inside tenant context.
     *
     * @use HasFactory<RestoreRequestFactory>
     */
    use CentralConnection, HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RestoreRequestStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * Get the agency whose record this is.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }

    /**
     * Get the agency admin who asked, even if their account was deleted since.
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    /**
     * Get the record the request is about, deleted or not.
     *
     * @return MorphTo<Model, $this>
     */
    public function restorable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }
}
