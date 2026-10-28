<?php

namespace App\Models;

use App\Enums\PlanChangeRequestStatus;
use Database\Factories\PlanChangeRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * An agency admin's request to be put on a plan from the price list.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string|null $pending_for_tenant_id
 * @property int $subscription_plan_id
 * @property int|null $requested_by
 * @property string|null $reason
 * @property PlanChangeRequestStatus $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant|null $tenant
 * @property-read SubscriptionPlan|null $plan
 * @property-read User|null $requester
 */
#[Fillable([
    'tenant_id',
    'pending_for_tenant_id',
    'subscription_plan_id',
    'requested_by',
    'reason',
    'status',
    'decided_by',
    'decided_at',
    'decision_reason',
])]
class PlanChangeRequest extends Model
{
    /**
     * The table lives in the central database, so the model must stay on the
     * central connection even when read from inside tenant context.
     *
     * @use HasFactory<PlanChangeRequestFactory>
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
            'status' => PlanChangeRequestStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * Get the agency that asked.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }

    /**
     * Get the plan asked for, even if it has since been withdrawn from the
     * price list.
     *
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id')->withTrashed();
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
}
