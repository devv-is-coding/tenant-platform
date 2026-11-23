<?php

namespace App\Models;

use App\Enums\ItemStatus;
use App\Enums\ItemType;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One listing in an agency's catalog: a tour, a visa or a hotel. One table for
 * all three, so one booking engine serves the whole catalog; what each type
 * needs beyond the common columns lives in its typed profile (profile()).
 *
 * No CentralConnection: the model uses whichever tenant database tenancy has
 * made the default, so it must only be touched inside tenant context. The
 * actor columns hold central user ids and are not foreign keys.
 *
 * @property int $id
 * @property ItemType $item_type
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ItemStatus $item_status
 * @property string|null $rejection_reason
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $published_at
 * @property int|null $created_by
 * @property int|null $edited_by
 * @property int|null $approved_by
 * @property int|null $deleted_by
 * @property bool $is_active
 * @property string|null $rating_avg
 * @property int $reviews_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read TourProfile|null $tourProfile
 * @property-read VisaProfile|null $visaProfile
 * @property-read HotelProfile|null $hotelProfile
 * @property-read Collection<int, Review> $reviews
 * @property-read Collection<int, WishlistItem> $wishlistItems
 */
#[Fillable([
    'item_type',
    'name',
    'slug',
    'description',
    'item_status',
    'rejection_reason',
    'submitted_at',
    'approved_at',
    'rejected_at',
    'published_at',
    'created_by',
    'edited_by',
    'approved_by',
    'deleted_by',
    'is_active',
])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
            'item_status' => ItemStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'published_at' => 'datetime',
            'is_active' => 'boolean',
            'rating_avg' => 'decimal:2',
            'reviews_count' => 'integer',
        ];
    }

    /**
     * Get the item's typed attributes: its tour, visa or hotel profile.
     */
    public function profile(): TourProfile|VisaProfile|HotelProfile|null
    {
        return match ($this->item_type) {
            ItemType::Tour => $this->tourProfile,
            ItemType::Visa => $this->visaProfile,
            ItemType::Hotel => $this->hotelProfile,
        };
    }

    /**
     * @return HasOne<TourProfile, $this>
     */
    public function tourProfile(): HasOne
    {
        return $this->hasOne(TourProfile::class);
    }

    /**
     * @return HasOne<VisaProfile, $this>
     */
    public function visaProfile(): HasOne
    {
        return $this->hasOne(VisaProfile::class);
    }

    /**
     * @return HasOne<HotelProfile, $this>
     */
    public function hotelProfile(): HasOne
    {
        return $this->hasOne(HotelProfile::class);
    }

    /**
     * Get the tour's stops in route order. Only tours have any.
     *
     * @return HasMany<ItineraryStop, $this>
     */
    public function itineraryStops(): HasMany
    {
        return $this->hasMany(ItineraryStop::class)->orderBy('sequence');
    }

    /**
     * Get the item's bookable variants, in the order they were added.
     *
     * @return HasMany<Option, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(Option::class)->orderBy('id');
    }

    /**
     * Get the item's gallery in display order.
     *
     * @return HasMany<Media, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Get the item's featured image, if it has one.
     *
     * @return HasOne<Media, $this>
     */
    public function featuredMedia(): HasOne
    {
        return $this->hasOne(Media::class, 'featured_for_item_id');
    }

    /**
     * Get the item's reviews, hidden ones included, newest first.
     *
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class)->latest('id');
    }

    /**
     * Get the customers' saves of the item.
     *
     * @return HasMany<WishlistItem, $this>
     */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /**
     * Recompute the rating from the approved reviews, then save it.
     *
     * Called inside the transaction that writes, hides, approves or deletes a
     * review, with the item's row locked. Counted from source every time,
     * never incremented, as a booking's money is.
     */
    public function recomputeRating(): void
    {
        $approved = Review::query()->whereBelongsTo($this)->approved();
        $count = $approved->count();

        $this->forceFill([
            'rating_avg' => $count > 0 ? round((float) $approved->avg('rating'), 2) : null,
            'reviews_count' => $count,
        ])->save();
    }
}
