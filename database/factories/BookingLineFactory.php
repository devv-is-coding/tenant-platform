<?php

namespace Database\Factories;

use App\Enums\AvailabilityMode;
use App\Enums\ItemType;
use App\Models\Booking;
use App\Models\BookingLine;
use App\Models\Option;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Lines live in an agency's own database: create them inside tenancy. A
 * factory line holds no units; book through BookingService for one that does.
 *
 * @extends Factory<BookingLine>
 */
class BookingLineFactory extends Factory
{
    /**
     * One Solo seat at 499.00 on a new booking, its snapshot taken from a new option.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'option_id' => Option::factory(),
            'item_id' => fn (array $attributes): int => Option::query()->whereKey($attributes['option_id'])->firstOrFail()->item_id,
            'item_name' => 'Japan Discovery',
            'option_name' => 'Solo',
            'item_type' => ItemType::Tour,
            'availability_mode' => AvailabilityMode::Capacity,
            'unit_price_minor' => 49900,
            'quantity' => 1,
            'line_total_minor' => 49900,
        ];
    }
}
