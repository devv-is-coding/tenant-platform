<?php

namespace Database\Factories;

use App\Enums\RefundStatus;
use App\Enums\RefundType;
use App\Models\Booking;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Refunds live in an agency's own database: create them inside tenancy. A
 * factory refund does not recompute its booking's amounts or return its
 * inventory; go through RefundService for that.
 *
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    /**
     * A requested 100.00 partial refund on a new booking, in its currency.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'type' => RefundType::Partial,
            'status' => RefundStatus::Requested,
            'amount_minor' => 10000,
            'currency' => fn (array $attributes): string => Booking::query()->whereKey($attributes['booking_id'])->value('currency') ?? 'PHP',
            'reason' => fake()->sentence(),
            'requested_at' => now(),
        ];
    }
}
