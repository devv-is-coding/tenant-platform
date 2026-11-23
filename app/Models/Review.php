<?php

namespace App\Models;

use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A customer's review of an item on one of their bookings, written through
 * ReviewService: one per booking (UNIQUE(customer_id, booking_id)), and only
 * once the booking is completed and paid in full.
 *
 * Approved on arrival, faithful to the legacy, and moderated after the fact:
 * staff can hide it (`is_approved` false), approve it again, feature it, or
 * delete it. Only approved reviews count toward the item's rating.
 *
 * `customer_id` is a central user in another database, so not a foreign key;
 * `customer_name` is snapshotted so the review still reads after the customer
 * record is gone.
 *
 * @property int $id
 * @property int $booking_id
 * @property int $item_id
 * @property int $customer_id
 * @property string $customer_name
 * @property string|null $title
 * @property string|null $content
 * @property int $rating
 * @property list<string>|null $image_paths
 * @property bool $is_approved
 * @property bool $is_featured
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Booking|null $booking
 * @property-read Item|null $item
 */
#[Fillable([
    'item_id',
    'customer_id',
    'customer_name',
    'title',
    'content',
    'rating',
    'image_paths',
])]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'rating' => 'integer',
            'image_paths' => 'array',
            'is_approved' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    /**
     * Get the booking the review is of.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the item reviewed.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Scope to the reviews customers see and the rating counts.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->where('is_approved', true);
    }
}
