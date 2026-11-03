<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BookingApprovalStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\FulfilmentStatus;
use App\ValueObjects\Money;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A customer's booking with the agency: one or more lines, each an option of
 * one of its items, so a single booking can hold two rooms, or a tour and a
 * visa (defect 4: the legacy allowed one option per booking).
 *
 * Three independent status axes: the manager's decision (`approval_status`),
 * the money (`payment_status`, always derived from the amounts), and whether
 * the trip happened (`fulfilment_status`). A rejected booking keeps its
 * payment status, as in the legacy.
 *
 * `customer_id` is a central user in another database, so it cannot be a
 * foreign key; `customer_name` and `customer_email` are snapshotted so the
 * booking stays legible after the customer record is gone. The currency is
 * snapshotted too: what was charged must not change if the agency later
 * switches currency, so every amount reads in this booking's own currency.
 *
 * @property int $id
 * @property string $reference
 * @property int $customer_id
 * @property string $customer_name
 * @property string $customer_email
 * @property BookingApprovalStatus $approval_status
 * @property BookingPaymentStatus $payment_status
 * @property FulfilmentStatus $fulfilment_status
 * @property string|null $rejection_reason
 * @property string $currency
 * @property Money $subtotal
 * @property Money $discount
 * @property Money $total
 * @property Money $amount_paid
 * @property Money $amount_refunded
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $total_minor
 * @property int $amount_paid_minor
 * @property int $amount_refunded_minor
 * @property Carbon|null $due_on
 * @property Carbon|null $expires_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $cancelled_at
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, BookingLine> $lines
 * @property-read Collection<int, InventoryAllocation> $allocations
 * @property-read User|null $customer
 */
#[Fillable([
    'reference',
    'customer_id',
    'customer_name',
    'customer_email',
    'approval_status',
    'payment_status',
    'fulfilment_status',
    'rejection_reason',
    'subtotal',
    'discount',
    'total',
    'due_on',
    'expires_at',
    'created_by',
])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes' defaults: a new booking waits for the manager, is
     * unpaid, and has not started.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'approval_status' => 0,
        'payment_status' => 1,
        'fulfilment_status' => 1,
        'discount_minor' => 0,
        'amount_paid_minor' => 0,
        'amount_refunded_minor' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'approval_status' => BookingApprovalStatus::class,
            'payment_status' => BookingPaymentStatus::class,
            'fulfilment_status' => FulfilmentStatus::class,
            'subtotal' => MoneyCast::class.':subtotal_minor,currency',
            'discount' => MoneyCast::class.':discount_minor,currency',
            'total' => MoneyCast::class.':total_minor,currency',
            'amount_paid' => MoneyCast::class.':amount_paid_minor,currency',
            'amount_refunded' => MoneyCast::class.':amount_refunded_minor,currency',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'amount_paid_minor' => 'integer',
            'amount_refunded_minor' => 'integer',
            'due_on' => 'date',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_by' => 'integer',
            'approved_by' => 'integer',
        ];
    }

    /**
     * Get the booking's lines, in the order they were booked.
     *
     * @return HasMany<BookingLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BookingLine::class)->orderBy('id');
    }

    /**
     * Get the units the booking holds, one row per unit per date.
     *
     * @return HasMany<InventoryAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryAllocation::class);
    }

    /**
     * Get the customer, a central user (see the class PHPDoc for why this is
     * not a foreign key).
     *
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
