<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One unit of an option held for one date by a booking line: the consumption
 * half of the inventory ledger. A two-room, three-night stay is six rows.
 *
 * UNIQUE(option_id, occupied_on, unit_index) is what makes overselling
 * impossible (see the migration), so this is the one model that does not
 * soft-delete: releasing a unit hard-deletes its row, or the tombstone would
 * keep the slot and block resale. History lives in the audit log.
 *
 * `occupied_on` is stored as a plain Y-m-d date on every driver: SQLite would
 * otherwise get Y-m-d 00:00:00 from a date cast, which neither equals the date
 * nor collides with it in the unique index.
 *
 * @property int $id
 * @property int $option_id
 * @property int $booking_id
 * @property int $booking_line_id
 * @property CarbonImmutable $occupied_on
 * @property int $unit_index
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Option|null $option
 * @property-read Booking|null $booking
 * @property-read BookingLine|null $line
 */
class InventoryAllocation extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'option_id' => 'integer',
            'booking_id' => 'integer',
            'booking_line_id' => 'integer',
            'unit_index' => 'integer',
        ];
    }

    /**
     * The date as a date, stored as Y-m-d.
     *
     * @return Attribute<CarbonImmutable, CarbonImmutable|string>
     */
    protected function occupiedOn(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): CarbonImmutable => CarbonImmutable::parse(substr($value, 0, 10)),
            set: fn (CarbonImmutable|string $value): string => $value instanceof CarbonImmutable ? $value->toDateString() : $value,
        );
    }

    /**
     * @return BelongsTo<Option, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<BookingLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(BookingLine::class, 'booking_line_id');
    }
}
