<?php

namespace App\Models;

use Database\Factories\CheckInFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Proof that the guest turned up, written only by FulfilmentService: staff
 * scanning the guest's booking QR (a hotel, `itinerary_stop_id` NULL), or the
 * guest scanning a tour stop's rotating QR on the guide's device.
 *
 * `customer_id` and `recorded_by` are central users in another database, so
 * neither is a foreign key. `stop_label` is snapshotted, so the history still
 * reads after the stop is deleted (the stop key is nulled, not cascaded).
 *
 * @property int $id
 * @property int $booking_id
 * @property int|null $itinerary_stop_id
 * @property string|null $stop_label
 * @property int $customer_id
 * @property Carbon $checked_in_at
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Booking|null $booking
 * @property-read ItineraryStop|null $itineraryStop
 */
#[Fillable([
    'itinerary_stop_id',
    'stop_label',
    'customer_id',
    'checked_in_at',
    'recorded_by',
])]
class CheckIn extends Model
{
    /** @use HasFactory<CheckInFactory> */
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
            'checked_in_at' => 'datetime',
            'recorded_by' => 'integer',
        ];
    }

    /**
     * Get the booking the guest checked in on.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the tour stop, or null for a booking-level (hotel) check-in or a
     * stop since deleted.
     *
     * @return BelongsTo<ItineraryStop, $this>
     */
    public function itineraryStop(): BelongsTo
    {
        return $this->belongsTo(ItineraryStop::class);
    }
}
