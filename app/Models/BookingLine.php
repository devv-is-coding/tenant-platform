<?php

namespace App\Models;

use App\Enums\AvailabilityMode;
use App\Enums\ItemType;
use Database\Factories\BookingLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One option on a booking: how many units, for which dates, at what price.
 *
 * The item and option are snapshotted (name, type, mode, unit price), so a
 * booking made today still reads correctly after the catalog is renamed and
 * repriced. Amounts are in the booking's currency.
 *
 * Dates follow the option's mode:
 * - **Fixed capacity** (a tour): the departure window, both days included;
 *   the units are held on the departure date; `nights` is null.
 * - **Per night** (a room): the stay, half-open (the check-out day is not a
 *   night); `nights` is the number of nights held.
 * - **Unlimited** (a visa): no dates, and nothing held.
 *
 * @property int $id
 * @property int $booking_id
 * @property int $option_id
 * @property int $item_id
 * @property string $item_name
 * @property string $option_name
 * @property ItemType $item_type
 * @property AvailabilityMode $availability_mode
 * @property int $unit_price_minor
 * @property int $quantity
 * @property int|null $nights
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property int $line_total_minor
 * @property int $adults
 * @property int $kids
 * @property int|null $baggage_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Booking|null $booking
 * @property-read Option|null $option
 * @property-read Collection<int, InventoryAllocation> $allocations
 */
#[Fillable([
    'booking_id',
    'option_id',
    'item_id',
    'item_name',
    'option_name',
    'item_type',
    'availability_mode',
    'unit_price_minor',
    'quantity',
    'nights',
    'starts_on',
    'ends_on',
    'line_total_minor',
    'adults',
    'kids',
    'baggage_count',
])]
class BookingLine extends Model
{
    /** @use HasFactory<BookingLineFactory> */
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
            'availability_mode' => AvailabilityMode::class,
            'unit_price_minor' => 'integer',
            'quantity' => 'integer',
            'nights' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'line_total_minor' => 'integer',
            'adults' => 'integer',
            'kids' => 'integer',
            'baggage_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the option as it is now; the line's own snapshot is what was sold.
     *
     * @return BelongsTo<Option, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class)->withTrashed();
    }

    /**
     * @return HasMany<InventoryAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryAllocation::class);
    }
}
