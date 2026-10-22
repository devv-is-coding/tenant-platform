<?php

namespace App\Models;

use App\Enums\BillingInterval;
use Database\Factories\SubscriptionPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A plan in the platform's price list. Agencies are put on a copy of it, held
 * as snapshot columns on the subscription; see the migration for why.
 *
 * @property int $id
 * @property string $name
 * @property int $amount_minor
 * @property string $currency
 * @property BillingInterval $interval
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'name',
    'amount_minor',
    'currency',
    'interval',
    'created_by',
])]
class SubscriptionPlan extends Model
{
    /**
     * The table lives in the central database, so the model must stay on the
     * central connection even when read from inside tenant context.
     *
     * @use HasFactory<SubscriptionPlanFactory>
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
            'interval' => BillingInterval::class,
            'amount_minor' => 'integer',
        ];
    }

    /**
     * Get the subscriptions started from this plan, live or ended.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
