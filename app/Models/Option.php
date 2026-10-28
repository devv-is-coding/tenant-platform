<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\AvailabilityMode;
use App\Enums\ItemType;
use App\ValueObjects\Money;
use Database\Factories\OptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/**
 * The bookable unit: a priced variant of an item, such as a tour's Solo or
 * Family, a hotel's Room 101, or a visa's e-Visa. One kind across all three
 * types is what lets one booking engine serve the whole catalog.
 *
 * The price is read and written as Money, in the agency's currency
 * (`tenant('currency')`): there is no currency column, and no stored stock.
 * Availability is always derived from the inventory ledger (TA-14), never
 * kept here, since a stored count was what drifted in the legacy.
 *
 * Every save refuses what the schema cannot: a per-night option on anything
 * but a hotel, nights on an option that is not per night, and a stay whose
 * shortest is longer than its longest.
 *
 * @property int $id
 * @property int $item_id
 * @property string $name
 * @property string|null $description
 * @property AvailabilityMode $availability_mode
 * @property int $price_minor
 * @property Money $price
 * @property int|null $min_nights
 * @property int|null $max_nights
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Item|null $item
 */
#[Fillable([
    'item_id',
    'name',
    'description',
    'availability_mode',
    'price',
    'min_nights',
    'max_nights',
    'is_active',
    'created_by',
    'deleted_by',
])]
class Option extends Model
{
    /** @use HasFactory<OptionFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Refuse a mode or a length of stay the option cannot have.
     */
    protected static function booted(): void
    {
        static::saving(function (self $option): void {
            $nightly = $option->availability_mode === AvailabilityMode::Nightly;

            if ($nightly && Item::withTrashed()->whereKey($option->item_id)->value('item_type') !== ItemType::Hotel) {
                throw new LogicException('Only a hotel sells by the night.');
            }

            if (! $nightly && ($option->min_nights !== null || $option->max_nights !== null)) {
                throw new InvalidArgumentException("A {$option->availability_mode->label()} option has no length of stay; only a per-night one does.");
            }

            if ($option->min_nights !== null && $option->min_nights < 1) {
                throw new InvalidArgumentException('A stay is at least one night.');
            }

            if ($option->min_nights !== null && $option->max_nights !== null && $option->min_nights > $option->max_nights) {
                throw new InvalidArgumentException("A stay cannot be at least {$option->min_nights} nights but at most {$option->max_nights}.");
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
            'availability_mode' => AvailabilityMode::class,
            'price_minor' => 'integer',
            'price' => MoneyCast::class.':price_minor',
            'min_nights' => 'integer',
            'max_nights' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the item the option is a variant of.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
