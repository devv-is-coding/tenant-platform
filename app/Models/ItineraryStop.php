<?php

namespace App\Models;

use App\Concerns\BelongsToItemOfType;
use App\Enums\ItemType;
use App\ValueObjects\DateRange;
use Database\Factories\ItineraryStopFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One stop on a tour's route, in `sequence` order. Only tours have stops
 * (ItemType::allowsItinerary()).
 *
 * Every save refuses an inverted stay (defect 3) and coordinates off the
 * globe. A stop with no coordinates is left off the map rather than plotted
 * at (0, 0).
 *
 * A deleted stop is soft-deleted and keeps its place in UNIQUE(item_id,
 * sequence), so a new stop takes the next sequence after every stop the tour
 * has had, deleted ones included (nextSequenceFor()).
 *
 * @property int $id
 * @property int $item_id
 * @property int $sequence
 * @property string $location
 * @property string|null $country_code
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property string|null $latitude
 * @property string|null $longitude
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Item|null $item
 */
#[Fillable([
    'item_id',
    'sequence',
    'location',
    'country_code',
    'starts_on',
    'ends_on',
    'latitude',
    'longitude',
])]
class ItineraryStop extends Model
{
    /** @use HasFactory<ItineraryStopFactory> */
    use BelongsToItemOfType, HasFactory, SoftDeletes;

    /**
     * Get the type of item that has an itinerary.
     */
    public static function itemType(): ItemType
    {
        return ItemType::Tour;
    }

    /**
     * Get the sequence a new stop on the tour takes: one past every stop it
     * has had, deleted ones included, since their sequence stays taken.
     */
    public static function nextSequenceFor(Item $item): int
    {
        return (int) static::withTrashed()->where('item_id', $item->id)->max('sequence') + 1;
    }

    /**
     * Refuse an inverted stay or impossible coordinates on every save.
     */
    protected static function booted(): void
    {
        static::saving(function (self $stop): void {
            if ($stop->starts_on !== null && $stop->ends_on !== null) {
                DateRange::of($stop->starts_on, $stop->ends_on);
            }

            if ($stop->latitude !== null && abs((float) $stop->latitude) > 90) {
                throw new InvalidArgumentException("Latitude {$stop->latitude} is not between -90 and 90.");
            }

            if ($stop->longitude !== null && abs((float) $stop->longitude) > 180) {
                throw new InvalidArgumentException("Longitude {$stop->longitude} is not between -180 and 180.");
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
        ];
    }
}
