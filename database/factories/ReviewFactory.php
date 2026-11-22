<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Item;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Reviews live in an agency's own database: create them inside tenancy. A
 * factory review does not recompute its item's rating, and skips the checks
 * on its booking; write one through ReviewService for both.
 *
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * An approved five-star review of a new item, on a new booking, by that
     * booking's customer.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'item_id' => Item::factory()->published(),
            'customer_id' => fn (array $attributes): int => (int) Booking::query()->whereKey($attributes['booking_id'])->value('customer_id'),
            'customer_name' => fn (array $attributes): string => (string) Booking::query()->whereKey($attributes['booking_id'])->value('customer_name'),
            'title' => 'Worth every peso',
            'content' => fake()->sentence(),
            'rating' => 5,
        ];
    }

    /**
     * A review staff have hidden.
     */
    public function hidden(): static
    {
        return $this->state(['is_approved' => false]);
    }
}
