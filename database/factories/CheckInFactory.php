<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\CheckIn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Check-ins live in an agency's own database: create them inside tenancy. A
 * factory check-in does not move its booking's fulfilment status; check in
 * through FulfilmentService for that.
 *
 * @extends Factory<CheckIn>
 */
class CheckInFactory extends Factory
{
    /**
     * A booking-level (hotel) check-in on a new booking, by its customer, now.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'customer_id' => fn (array $attributes): int => (int) Booking::query()->whereKey($attributes['booking_id'])->value('customer_id'),
            'checked_in_at' => now(),
        ];
    }
}
