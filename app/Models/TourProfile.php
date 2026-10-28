<?php

namespace App\Models;

use App\Casts\StringList;
use App\Concerns\BelongsToItemOfType;
use App\Enums\ItemType;
use App\ValueObjects\DateRange;
use Database\Factories\TourProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A tour's typed attributes, replacing the legacy EAV rows (defect 6).
 *
 * The departure window can never be inverted: saving one that ends before it
 * starts is refused, which is how the legacy came to hold a tour ending in the
 * year 0026 (defect 3).
 *
 * @property int $id
 * @property int $item_id
 * @property int $max_adults
 * @property int $max_kids
 * @property int|null $baggage_allowance_kg
 * @property string $meeting_point
 * @property list<string> $inclusions
 * @property Carbon $departs_on
 * @property Carbon $returns_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Item|null $item
 */
#[Fillable([
    'item_id',
    'max_adults',
    'max_kids',
    'baggage_allowance_kg',
    'meeting_point',
    'inclusions',
    'departs_on',
    'returns_on',
])]
class TourProfile extends Model
{
    /** @use HasFactory<TourProfileFactory> */
    use BelongsToItemOfType, HasFactory, SoftDeletes;

    /**
     * Get the type of item a tour profile belongs to.
     */
    public static function itemType(): ItemType
    {
        return ItemType::Tour;
    }

    /**
     * Refuse an inverted departure window on every save.
     */
    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            $profile->departureWindow();
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
            'max_adults' => 'integer',
            'max_kids' => 'integer',
            'baggage_allowance_kg' => 'integer',
            'inclusions' => StringList::class,
            'departs_on' => 'date',
            'returns_on' => 'date',
        ];
    }

    /**
     * Get the departure window, from the day the tour leaves to the day it
     * returns.
     *
     * @throws InvalidArgumentException when it ends before it starts
     */
    public function departureWindow(): DateRange
    {
        return DateRange::of($this->departs_on, $this->returns_on);
    }
}
