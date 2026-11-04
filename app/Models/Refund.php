<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\RefundStatus;
use App\Enums\RefundType;
use App\ValueObjects\Money;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Money going back to the customer of one booking, as many times as the
 * booking needs (defect 9: the legacy's eight columns on the booking allowed
 * one refund, ever). Requested, then approved (in full or in part) or
 * rejected with a reason, then processed: by hand for cash, through Stripe for
 * a card payment. Only a processed refund counts toward the booking's
 * `amount_refunded_minor`.
 *
 * @property int $id
 * @property int $booking_id
 * @property int|null $payment_id
 * @property RefundType $type
 * @property RefundStatus $status
 * @property Money $amount
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $reason
 * @property int|null $requested_by
 * @property Carbon|null $requested_at
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 * @property Carbon|null $processed_at
 * @property string|null $stripe_refund_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Booking|null $booking
 * @property-read Payment|null $payment
 */
#[Fillable([
    'payment_id',
    'type',
    'status',
    'amount',
    'reason',
    'requested_by',
    'requested_at',
    'decided_by',
    'decided_at',
    'decision_reason',
    'processed_at',
    'stripe_refund_id',
])]
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
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
            'type' => RefundType::class,
            'status' => RefundStatus::class,
            'requested_by' => 'integer',
            'requested_at' => 'datetime',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Get the booking the refund is for.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the payment the refund reverses, if it names one.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Scope the query to refunds whose money has gone back.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function countingAsRefunded(Builder $query): void
    {
        $query->whereIn('status', array_filter(
            RefundStatus::cases(),
            fn (RefundStatus $status): bool => $status->countsAsRefunded(),
        ));
    }

    /**
     * Scope the query to refunds still on their way: asked for, or approved
     * and not yet processed. Their amounts are spoken for, so no new refund
     * may count on them.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function outstanding(Builder $query): void
    {
        $query->whereIn('status', [RefundStatus::Requested, RefundStatus::Approved]);
    }
}
