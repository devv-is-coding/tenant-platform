<?php

namespace App\Services\Booking;

use App\ValueObjects\DateRange;

/**
 * One line a booking asks for: an option, how many, and for a room the stay.
 *
 * The option is named by its id and looked up again inside the agency taking
 * the booking, so an option of another agency can never be booked through it.
 * A tour departs on its own dates and a visa has none, so only a room (or a
 * fixed-capacity package on an item other than a tour) gives a stay.
 */
final readonly class BookingDraftLine
{
    public function __construct(
        public int $optionId,
        public int $quantity = 1,
        public ?DateRange $stay = null,
        public int $adults = 1,
        public int $kids = 0,
    ) {}
}
