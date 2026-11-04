<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\ValueObjects\Money;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Money received against one booking: cash or a bank transfer recorded by
 * staff, or a card payment confirmed by Stripe's webhook. The ledger the
 * booking's `amount_paid_minor` is recomputed from.
 *
 * The amount keeps its own currency, snapshotted from the booking, so an old
 * payment still reads correctly after the agency changes currency.
 * `idempotency_key` is unique: a gateway payment is keyed by what Stripe
 * settled, so a replayed webhook finds the row it already wrote.
 *
 * @property int $id
 * @property int $booking_id
 * @property string $reference
 * @property Money $amount
 * @property int $amount_minor
 * @property string $currency
 * @property PaymentMethod $method
 * @property PaymentRecordStatus $status
 * @property Carbon|null $paid_at
 * @property int|null $recorded_by
 * @property string|null $idempotency_key
 * @property string|null $stripe_payment_intent_id
 * @property string|null $stripe_charge_id
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Booking|null $booking
 * @property-read Collection<int, Refund> $refunds
 */
#[Fillable([
    'reference',
    'amount',
    'method',
    'status',
    'paid_at',
    'recorded_by',
    'idempotency_key',
    'stripe_payment_intent_id',
    'stripe_charge_id',
    'failure_reason',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class.':amount_minor,currency',
            'amount_minor' => 'integer',
            'method' => PaymentMethod::class,
            'status' => PaymentRecordStatus::class,
            'paid_at' => 'datetime',
            'recorded_by' => 'integer',
        ];
    }

    /**
     * Get the booking the payment was made against.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the refunds that reverse part or all of this payment.
     *
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * Determine if the money was settled through Stripe, so a refund of it
     * goes back through Stripe too.
     */
    public function isGatewaySettled(): bool
    {
        return $this->method->isGatewaySettled();
    }

    /**
     * Scope the query to payments that count toward the booking's paid amount.
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
