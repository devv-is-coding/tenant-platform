<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\TourProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TourProfile>
 */
class TourProfileFactory extends Factory
{
    /**
     * A twelve-day tour departing in a month, for a new tour item.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $departsOn = now()->addMonth()->startOfDay();

        return [
            'item_id' => Item::factory(),
            'max_adults' => 4,
            'max_kids' => 2,
            'baggage_allowance_kg' => 20,
            'meeting_point' => fake()->streetAddress(),
            'inclusions' => ['Return airfare', 'Hotel accommodation', 'Daily breakfast'],
            'departs_on' => $departsOn,
            'returns_on' => $departsOn->copy()->addDays(11),
        ];
    }
}
