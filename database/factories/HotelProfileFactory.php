<?php

namespace Database\Factories;

use App\Models\HotelProfile;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HotelProfile>
 */
class HotelProfileFactory extends Factory
{
    /**
     * A hotel with a 2 PM check-in and noon check-out, for a new hotel item.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory()->hotel(),
            'max_adults' => 2,
            'amenities' => ['Wi-Fi', 'Air conditioning', 'Breakfast included'],
            'check_in_time' => '14:00:00',
            'check_out_time' => '12:00:00',
        ];
    }
}
