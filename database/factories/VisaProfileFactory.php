<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\VisaProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisaProfile>
 */
class VisaProfileFactory extends Factory
{
    /**
     * A visa processed in five to ten days and valid for ninety, for a new
     * visa item.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory()->visa(),
            'processing_days_min' => 5,
            'processing_days_max' => 10,
            'validity_days' => 90,
            'requirements' => ['Valid passport', 'Bank certificate', 'Two ID photos'],
        ];
    }
}
