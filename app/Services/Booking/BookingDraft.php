<?php

namespace App\Services\Booking;

use App\Models\User;
use InvalidArgumentException;

/**
 * What a booking asks for, before BookingService checks and writes it: the
 * customer it is for, its lines, and who made it (the customer in their app,
 * or staff at the desk).
 */
final readonly class BookingDraft
{
    /**
     * @param  list<BookingDraftLine>  $lines
     *
     * @throws InvalidArgumentException for a draft with no lines
     */
    public function __construct(
        public User $customer,
        public array $lines,
        public ?User $createdBy = null,
    ) {
        if ($lines === []) {
            throw new InvalidArgumentException('A booking has at least one line.');
        }
    }
}
