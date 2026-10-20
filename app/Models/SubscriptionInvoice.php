<?php

namespace App\Models;

use App\Enums\SubscriptionInvoiceStatus;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * What the platform charged an agency for one billing period. Overdue is not a
 * column: it is derived from the status and the due date; see overdue().
 *
 * @property int $id
 * @property int $subscription_id
 * @property string $tenant_id
 * @property string $reference
 * @property Carbon $period_start_on
 * @property Carbon $period_end_on
 * @property Carbon $issued_on
 * @property Carbon $due_on
 * @property int $amount_minor
 * @property string $currency
 * @property int $amount_paid_minor
 * @property SubscriptionInvoiceStatus $status
 * @property Carbon|null $voided_on
 * @property string|null $void_reason
 * @property Carbon|null $created_on
 * @property Carbon|null $updated_on
 */
#[Fillable([
    'subscription_id',
    'tenant_id',
    'reference',
    'period_start_on',
    'period_end_on',
    'issued_on',
    'due_on',
    'amount_minor',
    'currency',
    'amount_paid_minor',
    'status',
    'voided_on',
    'void_reason',
])]
class SubscriptionInvoice extends Model
{
    /**
     * The table lives in the central database, so the model must stay on the
     * central connection even when read from inside tenant context.
     *
     * @use HasFactory<SubscriptionInvoiceFactory>
     */
    use CentralConnection, HasFactory;

    const CREATED_AT = 'created_on';

    const UPDATED_AT = 'updated_on';

    /**
     * Mirrors the column defaults, so a freshly issued invoice reports Open and
     * nothing paid without being reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'amount_paid_minor' => 0,
        'status' => 1,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionInvoiceStatus::class,
            'amount_minor' => 'integer',
            'amount_paid_minor' => 'integer',
            'period_start_on' => 'date',
            'period_end_on' => 'date',
            'issued_on' => 'date',
            'due_on' => 'date',
            'voided_on' => 'datetime',
        ];
    }

    /**
     * Get the subscription this invoice bills a period of.
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the agency this invoice is addressed to.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the amount still owed, in minor units.
     */
    public function outstandingMinor(): int
    {
        return max(0, $this->amount_minor - $this->amount_paid_minor);
    }

    /**
     * Determine if the invoice is still owed and its due date has passed.
     */
    public function isOverdue(CarbonInterface $today): bool
    {
        return $this->status->isOutstanding() && $this->due_on->lt($today->copy()->startOfDay());
    }

    /**
     * Match invoices still owed after their due date.
     *
     * Derived on every read rather than stored: a stored flag would need a job
     * to flip it and would be wrong the first time that job did not run. A
     * void invoice is never overdue, since the charge was withdrawn.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overdue(Builder $query, CarbonInterface $today): void
    {
        $query->whereIn('status', [SubscriptionInvoiceStatus::Open, SubscriptionInvoiceStatus::PartiallyPaid])
            ->whereDate('due_on', '<', $today);
    }
}
