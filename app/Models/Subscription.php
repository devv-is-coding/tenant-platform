<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * The platform's commercial relationship with one agency. The plan is held as
 * snapshot columns rather than a foreign key; see the migration for why.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string|null $active_for_tenant_id
 * @property string $plan_name
 * @property int $amount_minor
 * @property string $currency
 * @property BillingInterval $interval
 * @property SubscriptionStatus $status
 * @property Carbon $starts_on
 * @property Carbon $current_period_start_on
 * @property Carbon $current_period_end_on
 * @property Carbon|null $next_billing_on
 * @property Carbon|null $trial_ends_on
 * @property Carbon|null $cancelled_on
 * @property string|null $cancel_reason
 * @property Carbon|null $ends_on
 * @property int|null $created_by
 * @property Carbon|null $created_on
 * @property Carbon|null $updated_on
 * @property Carbon|null $deleted_on
 */
#[Fillable([
    'tenant_id',
    'active_for_tenant_id',
    'plan_name',
    'amount_minor',
    'currency',
    'interval',
    'status',
    'starts_on',
    'current_period_start_on',
    'current_period_end_on',
    'next_billing_on',
    'trial_ends_on',
    'cancelled_on',
    'cancel_reason',
    'ends_on',
    'created_by',
])]
class Subscription extends Model
{
    /**
     * The table lives in the central database, so the model must stay on the
     * central connection even when read from inside tenant context.
     *
     * @use HasFactory<SubscriptionFactory>
     */
    use CentralConnection, HasFactory, SoftDeletes;

    const CREATED_AT = 'created_on';

    const UPDATED_AT = 'updated_on';

    const DELETED_AT = 'deleted_on';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interval' => BillingInterval::class,
            'status' => SubscriptionStatus::class,
            'amount_minor' => 'integer',
            'starts_on' => 'date',
            'current_period_start_on' => 'date',
            'current_period_end_on' => 'date',
            'next_billing_on' => 'date',
            'trial_ends_on' => 'date',
            'cancelled_on' => 'datetime',
            'ends_on' => 'date',
        ];
    }

    /**
     * Get the agency this subscription bills.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the invoices issued for this subscription's billing periods.
     *
     * @return HasMany<SubscriptionInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }
}
