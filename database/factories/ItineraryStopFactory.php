<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItineraryStop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItineraryStop>
 */
class ItineraryStopFactory extends Factory
{
    /**
     * The first stop of a new tour: three nights in Tokyo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsOn = now()->addMonth()->startOfDay();

        return [
            'item_id' => Item::factory(),
            'sequence' => 1,
            'location' => 'Tokyo',
            'country_code' => 'JP',
            'starts_on' => $startsOn,
            'ends_on' => $startsOn->copy()->addDays(3),
            'latitude' => '35.676200',
            'longitude' => '139.650300',
        ];
    }
}
