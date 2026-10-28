<?php

namespace Database\Factories;

use App\Enums\AvailabilityMode;
use App\Models\Item;
use App\Models\Option;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Options live in an agency's own database, priced in its currency: create
 * them inside tenancy, in an agency with a currency.
 *
 * @extends Factory<Option>
 */
class OptionFactory extends Factory
{
    /**
     * A fixed-capacity option on a new tour, at 499.00.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'name' => 'Solo',
            'availability_mode' => AvailabilityMode::Capacity,
            'price_minor' => 49900,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the option is a room sold by the night. Give it a hotel:
     * `->for(Item::factory()->hotel())`, since only a hotel sells by the
     * night. The state sets no item, which would override the one given.
     */
    public function nightly(): static
    {
        return $this->state(fn () => [
            'name' => 'Room 101',
            'availability_mode' => AvailabilityMode::Nightly,
            'price_minor' => 19900,
            'min_nights' => 1,
            'max_nights' => 30,
        ]);
    }

    /**
     * Indicate that the option tracks no stock, as a visa's does. Give it a
     * visa: `->for(Item::factory()->visa())`.
     */
    public function unlimited(): static
    {
        return $this->state(fn () => [
            'name' => 'e-Visa',
            'availability_mode' => AvailabilityMode::Unlimited,
        ]);
    }
}
