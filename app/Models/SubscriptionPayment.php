<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use Database\Factories\SubscriptionPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Money an agency paid the platform against one invoice. The ledger that the
 * invoice's `amount_paid_minor` is recomputed from.
 *
 * @property int $id
 * @property int $subscription_invoice_id
 * @property string $tenant_id
 * @property string $reference
 * @property int $amount_minor
 * @property string $currency
 * @property PaymentMethod $method
 * @property PaymentRecordStatus $status
 * @property Carbon|null $paid_on
 * @property int|null $recorded_by
 * @property string|null $idempotency_key
 * @property string|null $stripe_payment_intent_id
 * @property string|null $stripe_charge_id
 * @property string|null $failure_reason
 * @property Carbon|null $created_on
 * @property Carbon|null $updated_on
 */
#[Fillable([
    'subscription_invoice_id',
    'tenant_id',
    'reference',
    'amount_minor',
    'currency',
    'method',
    'status',
    'paid_on',
    'recorded_by',
    'idempotency_key',
    'stripe_payment_intent_id',
    'stripe_charge_id',
    'failure_reason',
])]
class SubscriptionPayment extends Model
{
    /**
     * The table lives in the central database, so the model must stay on the
     * central connection even when read from inside tenant context.
     *
     * @use HasFactory<SubscriptionPaymentFactory>
     */
    use CentralConnection, HasFactory;

    const CREATED_AT = 'created_on';

    const UPDATED_AT = 'updated_on';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentRecordStatus::class,
            'amount_minor' => 'integer',
            'paid_on' => 'datetime',
        ];
    }

    /**
     * Get the invoice this payment settles part or all of.
     *
     * @return BelongsTo<SubscriptionInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }

    /**
     * Get the agency that paid.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the super admin who recorded the payment by hand, if anyone did.
     *
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Match payments whose money counts toward an invoice's paid amount.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function countingAsPaid(Builder $query): void
    {
        $query->whereIn('status', array_filter(
            PaymentRecordStatus::cases(),
            fn (PaymentRecordStatus $status): bool => $status->countsAsPaid(),
        ));
    }
}
